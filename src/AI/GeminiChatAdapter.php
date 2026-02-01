<?php

declare(strict_types=1);

namespace App\AI;

use App\Tracing\Trace;
use App\Tracing\TraceContext;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\Chat\Request\AIChatRequest;
use ModelflowAi\Chat\Response\AIChatResponse;
use Psr\Log\LoggerInterface;

final readonly class GeminiChatAdapter implements AIChatAdapterInterface
{
    public function __construct(
        private AIChatAdapterInterface $inner,
        private LoggerInterface $logger,
        /**
         * Wenn true, hängt der Fallback eine kurze Detailinfo an (gekürzt/sanitized).
         * Empfehlung: nur in DEV aktivieren.
         */
        private bool $includeVendorDetails = false,
        /**
         * Wenn false, wird bei erkannten Vendor-Problemen die Exception weitergeworfen (DEV/TEST).
         * Empfehlung: in PROD true.
         */
        private bool $enableFallback = true,
        /**
         * Optional: harte Obergrenze für Details/Snippets im Log/DEV-Fallback.
         */
        private int $maxDetailLen = 260,
    ) {}

    public function supports(object $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handleRequest(object $request): AIChatResponse
    {
        $trace = TraceContext::get();

        // Ohne Trace: trotzdem robust behandeln.
        if (!$trace) {
            return $this->callInnerWithFallback($request, null);
        }

        return $trace->span(
            'adapter.gemini.handleRequest',
            function () use ($trace, $request): AIChatResponse {
                $meta = $this->buildMeta($request);

                return $trace->span(
                    'adapter.gemini.vendor_call',
                    function () use ($request, $trace): AIChatResponse {
                        return $this->callInnerWithFallback($request, $trace);
                    },
                    $meta
                );
            },
            [
                'inner_class' => $this->inner::class,
            ]
        );
    }

    /**
     * Zentraler Call: einheitliche Fehlerbehandlung + Logging.
     */
    private function callInnerWithFallback(object $request, ?Trace $trace): AIChatResponse
    {
        $start = microtime(true);

        try {
            $resp = $this->inner->handleRequest($request);

            // Optional: erfolgreiche Calls minimal loggen (nur wenn ihr das wollt)
            // $this->logger->debug('gemini.ok', [
            //     'provider' => 'gemini',
            //     'inner' => $this->inner::class,
            //     'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            // ]);

            return $resp;
        } catch (\RuntimeException $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            $reason = $this->classifyNonSimpleTextReason($e->getMessage());

            // Nur bekannte Gemini-Sonderfälle abfangen.
            if ($this->isGeminiNonSimpleTextOrSafetyBlockedError($e)) {
                $this->logVendorException($request, $e, $trace, $reason, $latencyMs);

                if (!$this->enableFallback) {
                    throw $e;
                }

                return $this->makeUserFriendlyFallbackResponse($e, $reason);
            }

            // Unbekannte RuntimeException: loggen und weiterwerfen (damit echte Bugs nicht verdeckt werden).
            $this->logVendorException($request, $e, $trace, 'runtime_unknown', $latencyMs);
            throw $e;
        }
    }

    /**
     * Gemini / Google SDK wirft RuntimeException, wenn:
     * - Safety blockt (promptFeedback) -> keine simple text response
     * - oder response ist multi-part und vendor adapter nutzt ->text()
     *
     * Wir matchen bewusst "fuzzy", weil Message-Prefixe variieren können.
     */
    private function isGeminiNonSimpleTextOrSafetyBlockedError(\RuntimeException $e): bool
    {
        $m = $e->getMessage();

        // 1) klassischer Multi-Part Text()-Fehler
        $isNonSimpleText = str_contains($m, 'GenerateContentResponse::text()')
            && (
                str_contains($m, 'quick accessor')
                || str_contains($m, 'only works for simple')
                || str_contains($m, 'single-`Part`')
                || str_contains($m, 'single Part')
            )
            && (
                str_contains($m, 'parts()')
                || str_contains($m, 'parts')
                || str_contains($m, 'content.parts')
                || str_contains($m, 'candidates')
            );

        // 2) Safety block Hinweis (kommt oft als Prefix davor)
        $isSafetyBlocked = str_contains($m, 'Request blocked by safety settings')
            || str_contains($m, 'blocked by safety')
            || str_contains($m, 'promptFeedback');

        return $isNonSimpleText || $isSafetyBlocked;
    }

    /**
     * Nutzerfreundlicher Fallback (für normale Mitarbeitende).
     * Technische Details nur optional in DEV.
     */
    private function makeUserFriendlyFallbackResponse(\RuntimeException $e, string $reason): AIChatResponse
    {
        // Safety: nicht als "Fehler" formulieren, sondern als Einschränkung.
        if ($reason === 'safety_blocked' || $reason === 'prompt_feedback') {
            $msg = "Dabei kann ich dir leider nicht helfen. Bitte formuliere die Anfrage anders oder wende dich an die interne IT/Teamleitung, falls es dringend ist.";
            return $this->makeTextResponse($msg);
        }

        // Multi-Part / Text-Accessor: meistens ein Vendor-Format-Thema.
        $msg = "Ich konnte gerade keine saubere Text-Antwort erzeugen. Bitte schreib die Frage noch einmal etwas konkreter (z.B. Stichwort + Kontext) – oder probiere es in 10 Sekunden erneut.";

        if ($this->includeVendorDetails) {
            $detail = $this->makeSafeShortDetail($e->getMessage());
            if ($detail !== '') {
                $msg .= ' (DEV-Details: ' . $detail . ')';
            }
        }

        return $this->makeTextResponse($msg);
    }

    /**
     * Kompatibel halten mit mehreren ModelflowAi Versionen.
     */
    private function makeTextResponse(string $msg): AIChatResponse
    {
        if (method_exists(AIChatResponse::class, 'fromText')) {
            /** @phpstan-ignore-next-line */
            return AIChatResponse::fromText($msg);
        }
        if (method_exists(AIChatResponse::class, 'fromString')) {
            /** @phpstan-ignore-next-line */
            return AIChatResponse::fromString($msg);
        }

        // Worst case:
        try {
            /** @phpstan-ignore-next-line */
            return new AIChatResponse($msg);
        } catch (\Throwable) {
            throw new \RuntimeException($msg);
        }
    }

    /**
     * Meta für Tracing/Debug: ohne PII, ohne Prompt-Inhalte.
     */
    private function buildMeta(object $request): array
    {
        $meta = [
            'provider' => 'gemini',
            'request_class' => $request::class,
            'inner_class' => $this->inner::class,
        ];

        if ($request instanceof AIChatRequest && method_exists($request, 'getMessages')) {
            try {
                $msgs = $request->getMessages();
                $meta['messages_type'] = is_object($msgs) ? $msgs::class : gettype($msgs);
                $meta['messages_count'] = is_array($msgs)
                    ? count($msgs)
                    : ($msgs instanceof \Countable ? count($msgs) : null);

                // Optional: nur grobe Strukturinfos, keine Inhalte
                $meta += $this->summarizeMessagesForMeta($msgs);
            } catch (\Throwable) {
                // niemals wegen Meta-Build scheitern
            }
        }

        return $meta;
    }

    /**
     * Summarize messages ohne Inhalte.
     * Wir versuchen defensiv verschiedene Message-Formate.
     */
    private function summarizeMessagesForMeta(mixed $msgs): array
    {
        $out = [
            'roles' => null,
            'content_lens' => null,
        ];

        if (!is_array($msgs) && $msgs instanceof \Traversable) {
            $msgs = iterator_to_array($msgs, false);
        }

        if (!is_array($msgs)) {
            return $out;
        }

        $roles = [];
        $lens  = [];

        foreach ($msgs as $m) {
            $role = null;
            $len = null;

            if (is_array($m)) {
                if (isset($m['role']) && is_string($m['role'])) {
                    $role = $m['role'];
                }
                if (array_key_exists('content', $m)) {
                    $len = $this->estimateContentLen($m['content']);
                }
            } elseif (is_object($m)) {
                if (method_exists($m, 'getRole')) {
                    $r = $m->getRole();
                    if (is_string($r)) {
                        $role = $r;
                    }
                } elseif (property_exists($m, 'role') && is_string($m->role)) {
                    $role = $m->role;
                }

                if (method_exists($m, 'getContent')) {
                    $len = $this->estimateContentLen($m->getContent());
                } elseif (property_exists($m, 'content')) {
                    $len = $this->estimateContentLen($m->content);
                }
            }

            $roles[] = $role ?? 'unknown';
            $lens[]  = $len;
        }

        $out['roles'] = $roles;
        $out['content_lens'] = $lens;

        return $out;
    }

    private function estimateContentLen(mixed $content): ?int
    {
        if (is_string($content)) {
            return mb_strlen($content);
        }

        // manchmal ist content ein Array von parts
        if (is_array($content)) {
            $sum = 0;
            foreach ($content as $p) {
                if (is_string($p)) {
                    $sum += mb_strlen($p);
                } elseif (is_array($p) && isset($p['text']) && is_string($p['text'])) {
                    $sum += mb_strlen($p['text']);
                } elseif (is_object($p) && method_exists($p, 'text')) {
                    $t = $p->text();
                    if (is_string($t)) {
                        $sum += mb_strlen($t);
                    }
                }
            }
            return $sum;
        }

        return null;
    }

    /**
     * Sanitize/verkürzen: keine Zeilenumbrüche, keine Riesentexte.
     */
    private function makeSafeShortDetail(string $raw): string
    {
        $detail = trim($raw);
        if ($detail === '') {
            return '';
        }

        // Whitespace normalisieren
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        // harte Längenbegrenzung
        $max = max(80, $this->maxDetailLen);
        if (mb_strlen($detail) > $max) {
            $detail = mb_substr($detail, 0, $max) . '…';
        }

        return $detail;
    }

    /**
     * Zentrales Logging: ohne Prompt-Inhalte, aber mit Klassifizierung.
     */
    private function logVendorException(
        object $request,
        \RuntimeException $e,
        ?Trace $trace,
        string $reason,
        int $latencyMs
    ): void {
        $ctx = [
            'provider' => 'gemini',
            'inner' => $this->inner::class,
            'request_class' => $request::class,
            'reason' => $reason,
            'latency_ms' => $latencyMs,
            'message_snippet' => $this->makeSafeShortDetail($e->getMessage()),
            'has_trace' => $trace !== null,
        ];

        // Optionale Meta-Infos, ohne Inhalte
        if ($request instanceof AIChatRequest && method_exists($request, 'getMessages')) {
            try {
                $msgs = $request->getMessages();
                $ctx['messages_type'] = is_object($msgs) ? $msgs::class : gettype($msgs);
                $ctx['messages_count'] = is_array($msgs) ? count($msgs) : ($msgs instanceof \Countable ? count($msgs) : null);
                $ctx += $this->summarizeMessagesForMeta($msgs);
            } catch (\Throwable) {
                // niemals wegen Logging scheitern
            }
        }

        // Severity je nach Grund
        if ($reason === 'runtime_unknown') {
            $this->logger->error('ai.gemini.runtime_exception', $ctx);
            return;
        }

        // Known vendor quirks => warning
        $this->logger->warning('ai.gemini.non_simple_text_or_safety_block', $ctx);
    }

    private function classifyNonSimpleTextReason(string $message): string
    {
        if (str_contains($message, 'Request blocked by safety settings') || str_contains($message, 'blocked by safety')) {
            return 'safety_blocked';
        }
        if (str_contains($message, 'promptFeedback')) {
            return 'prompt_feedback';
        }
        if (str_contains($message, 'GenerateContentResponse::text()')) {
            return 'multipart_text_accessor';
        }
        return 'unknown_non_simple';
    }
}
