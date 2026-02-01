<?php

declare(strict_types=1);

namespace App\AI;

use App\Tracing\Trace;
use App\Tracing\TraceContext;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\Chat\Request\AIChatRequest;
use ModelflowAi\Chat\Response\AIChatResponse;
use ModelflowAi\Chat\Response\AIChatResponseMessage;
use OpenAI\Client;
use Psr\Log\LoggerInterface;

final readonly class OpenAiChatAdapter implements AIChatAdapterInterface
{
    public function __construct(
        private Client $client,
        private string $model,
        private LoggerInterface $logger,
        private ?array $responseFormat = null,
        private ?float $temperature = null,
        /**
         * Wenn true, hängt der Fallback eine kurze Detailinfo an (gekürzt/sanitized).
         * Empfehlung: nur in DEV aktivieren.
         */
        private bool $includeVendorDetails = false,
        /**
         * Wenn true, wird bei typischen Vendor-Problemen eine nutzerfreundliche Antwort geliefert.
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
        return $request instanceof AIChatRequest;
    }

    public function handleRequest(object $request): AIChatResponse
    {
        /** @var Trace|null $trace */
        $trace = TraceContext::get();

        if (!$request instanceof AIChatRequest) {
            throw new \InvalidArgumentException('Unsupported request type');
        }

        $run = function () use ($request): AIChatResponse {
            // Payload bauen (nur text, keine Inhalte loggen)
            $messages = [];
            foreach ($this->extractRequestMessages($request) as $msg) {
                [$role, $content] = $this->normalizeMessage($msg);
                $content = trim($content);
                if ($content === '') {
                    continue;
                }
                $messages[] = ['role' => $role, 'content' => $content];
            }

            $payload = [
                'model' => $this->model,
                'messages' => $messages,
            ];

            if ($this->temperature !== null) {
                $payload['temperature'] = $this->temperature;
            }
            if ($this->responseFormat !== null) {
                $payload['response_format'] = $this->responseFormat; // z.B. ['type' => 'json_object']
            }

            // Vendor Call
            $raw = $this->client->chat()->create($payload);

            $text = trim((string) ($raw->choices[0]->message->content ?? ''));

            // OpenAI kann auch "refusal" liefern (SDK abhängig), oder schlicht leer.
            if ($text === '') {
                $text = '[leere Antwort]';
            }

            $responseMessage = $this->makeResponseMessage($text);
            $usage = $this->makeUsageObject($raw);

            return $this->makeResponse($request, $responseMessage, $raw, $usage);
        };

        // Ohne Trace: trotzdem robust behandeln.
        if (!$trace) {
            return $this->callWithFallback($request, null, $run);
        }

        return $trace->span(
            'adapter.openai.handleRequest',
            function () use ($trace, $request, $run): AIChatResponse {
                $meta = $this->buildMeta($request);

                $meta['provider'] = 'openai';
                $meta['model'] = $this->model;
                $meta['response_format'] = $this->responseFormat ? json_encode($this->responseFormat) : null;
                $meta['temperature'] = $this->temperature;

                return $trace->span(
                    'adapter.openai.vendor_call',
                    fn () => $this->callWithFallback($request, $trace, $run),
                    $meta
                );
            },
            [
                'model' => $this->model,
            ]
        );
    }

    /**
     * Zentral: einheitliche Fehlerbehandlung + Logging.
     */
    private function callWithFallback(AIChatRequest $request, ?Trace $trace, callable $run): AIChatResponse
    {
        $start = microtime(true);

        try {
            return $run();
        } catch (\RuntimeException $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $reason = $this->classifyOpenAiRuntimeReason($e->getMessage());

            // bekannte "Vendor/Netz"-Fälle: Rate limit, timeout, service down, bad gateway, etc.
            if ($this->isLikelyOpenAiTransientOrVendorError($e)) {
                $this->logVendorException($request, $e, $trace, $reason, $latencyMs);

                if (!$this->enableFallback) {
                    throw $e;
                }

                return $this->makeUserFriendlyFallbackResponse($e, $reason);
            }

            // Unbekannt: loggen und weiterwerfen (damit echte Bugs nicht verdeckt werden).
            $this->logVendorException($request, $e, $trace, 'runtime_unknown', $latencyMs);
            throw $e;
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            // Alles andere: loggen und weiterwerfen (harte Fehler)
            $this->logThrowable($request, $e, $trace, $latencyMs);
            throw $e;
        }
    }

    /**
     * Heuristik: OpenAI SDK wirft je nach Transport/HTTP-Client RuntimeException mit typischen Mustern.
     * Wir matchen bewusst "fuzzy", um Vendor-Textvarianten zu tolerieren.
     */
    private function isLikelyOpenAiTransientOrVendorError(\RuntimeException $e): bool
    {
        $m = mb_strtolower($e->getMessage());

        // Rate limit / quota
        if (str_contains($m, 'rate limit') || str_contains($m, 'too many requests') || str_contains($m, 'quota')) {
            return true;
        }

        // Timeouts / network
        if (str_contains($m, 'timeout') || str_contains($m, 'timed out') || str_contains($m, 'connection') || str_contains($m, 'connect')) {
            return true;
        }

        // Service / Gateway / overload
        if (
            str_contains($m, 'service unavailable')
            || str_contains($m, 'bad gateway')
            || str_contains($m, 'gateway')
            || str_contains($m, 'overloaded')
            || str_contains($m, 'internal server error')
        ) {
            return true;
        }

        // JSON / response format / parsing
        if (str_contains($m, 'json') && (str_contains($m, 'parse') || str_contains($m, 'decode') || str_contains($m, 'malformed'))) {
            return true;
        }

        // Policy/refusal (je nach SDK/Client)
        if (str_contains($m, 'refus') || str_contains($m, 'policy') || str_contains($m, 'safety')) {
            return true;
        }

        // Generic vendor wording
        if (str_contains($m, 'openai') && (str_contains($m, 'api') || str_contains($m, 'request') || str_contains($m, 'response'))) {
            return true;
        }

        return false;
    }

    private function makeUserFriendlyFallbackResponse(\RuntimeException $e, string $reason): AIChatResponse
    {
        // Für normale Mitarbeitende: ruhig, ohne Technik.
        if ($reason === 'rate_limited') {
            $msg = 'Gerade sind sehr viele Anfragen gleichzeitig aktiv. Bitte versuche es in 10–20 Sekunden erneut.';
            return $this->makeTextResponse($msg);
        }

        if ($reason === 'timeout_or_network') {
            $msg = 'Die Verbindung war gerade instabil. Bitte sende die Anfrage erneut (kurz und konkret hilft).';
            return $this->makeTextResponse($msg);
        }

        if ($reason === 'service_unavailable') {
            $msg = 'Der KI-Dienst ist im Moment nicht erreichbar. Bitte versuche es gleich nochmal.';
            return $this->makeTextResponse($msg);
        }

        if ($reason === 'policy_or_refusal') {
            $msg = 'Dabei kann ich dir leider nicht helfen. Bitte formuliere die Anfrage anders oder wende dich an die interne IT/Teamleitung, falls es dringend ist.';
            return $this->makeTextResponse($msg);
        }

        // Fallback für sonstige Vendor-Probleme
        $msg = 'Ich konnte gerade keine Antwort erzeugen. Bitte formuliere die Anfrage etwas konkreter oder probiere es erneut.';

        if ($this->includeVendorDetails) {
            $detail = $this->makeSafeShortDetail($e->getMessage());
            if ($detail !== '') {
                $msg .= ' (DEV-Details: ' . $detail . ')';
            }
        }

        return $this->makeTextResponse($msg);
    }

    private function buildMeta(AIChatRequest $request): array
    {
        $meta = [
            'provider' => 'openai',
            'request_class' => $request::class,
            'model' => $this->model,
        ];

        if (method_exists($request, 'getMessages')) {
            try {
                $msgs = $request->getMessages();
                $meta['messages_type'] = is_object($msgs) ? $msgs::class : gettype($msgs);
                $meta['messages_count'] = is_array($msgs) ? count($msgs) : ($msgs instanceof \Countable ? count($msgs) : null);
                $meta += $this->summarizeMessagesForMeta($msgs);
            } catch (\Throwable) {
                // nie wegen Meta scheitern
            }
        }

        return $meta;
    }

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
                    } elseif (is_object($r) && property_exists($r, 'value')) {
                        $role = (string) $r->value;
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
        if (is_array($content)) {
            $sum = 0;
            foreach ($content as $p) {
                if (is_string($p)) {
                    $sum += mb_strlen($p);
                } elseif (is_array($p) && isset($p['text']) && is_string($p['text'])) {
                    $sum += mb_strlen($p['text']);
                }
            }
            return $sum;
        }
        return null;
    }

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

        try {
            /** @phpstan-ignore-next-line */
            return new AIChatResponse($msg);
        } catch (\Throwable) {
            throw new \RuntimeException($msg);
        }
    }

    private function makeSafeShortDetail(string $raw): string
    {
        $detail = trim($raw);
        if ($detail === '') {
            return '';
        }

        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        $max = max(80, $this->maxDetailLen);
        if (mb_strlen($detail) > $max) {
            $detail = mb_substr($detail, 0, $max) . '…';
        }

        return $detail;
    }

    private function classifyOpenAiRuntimeReason(string $message): string
    {
        $m = mb_strtolower($message);

        if (str_contains($m, 'rate limit') || str_contains($m, 'too many requests') || str_contains($m, 'quota') || str_contains($m, '429')) {
            return 'rate_limited';
        }
        if (str_contains($m, 'timeout') || str_contains($m, 'timed out') || str_contains($m, 'connection') || str_contains($m, 'connect')) {
            return 'timeout_or_network';
        }
        if (str_contains($m, 'service unavailable') || str_contains($m, 'bad gateway') || str_contains($m, '502') || str_contains($m, '503') || str_contains($m, 'overloaded')) {
            return 'service_unavailable';
        }
        if (str_contains($m, 'json') && (str_contains($m, 'parse') || str_contains($m, 'decode') || str_contains($m, 'malformed'))) {
            return 'json_parse';
        }
        if (str_contains($m, 'refus') || str_contains($m, 'policy') || str_contains($m, 'safety')) {
            return 'policy_or_refusal';
        }

        return 'vendor_unknown';
    }

    private function logVendorException(
        AIChatRequest $request,
        \RuntimeException $e,
        ?Trace $trace,
        string $reason,
        int $latencyMs
    ): void {
        $ctx = [
            'provider' => 'openai',
            'model' => $this->model,
            'request_class' => $request::class,
            'reason' => $reason,
            'latency_ms' => $latencyMs,
            'message_snippet' => $this->makeSafeShortDetail($e->getMessage()),
            'has_trace' => $trace !== null,
            'response_format' => $this->responseFormat ? json_encode($this->responseFormat) : null,
            'temperature' => $this->temperature,
        ];

        // Optional: Strukturinfos ohne Inhalte
        try {
            $msgs = $this->extractRequestMessages($request);
            $ctx['messages_count'] = is_array($msgs) ? count($msgs) : null;
            $ctx += $this->summarizeMessagesForMeta($msgs);
        } catch (\Throwable) {
            // never fail
        }

        // Severity je nach Grund
        if ($reason === 'runtime_unknown') {
            $this->logger->error('ai.openai.runtime_exception', $ctx);
            return;
        }

        $this->logger->warning('ai.openai.vendor_or_transient_error', $ctx);
    }

    private function logThrowable(AIChatRequest $request, \Throwable $e, ?Trace $trace, int $latencyMs): void
    {
        $this->logger->error('ai.openai.throwable', [
            'provider' => 'openai',
            'model' => $this->model,
            'request_class' => $request::class,
            'exception_class' => $e::class,
            'latency_ms' => $latencyMs,
            'message_snippet' => $this->makeSafeShortDetail($e->getMessage()),
            'has_trace' => $trace !== null,
        ]);
    }

    // -------------------------
    // Bestehende Helfer (unverändert)
    // -------------------------

    private function extractRequestMessages(AIChatRequest $request): array
    {
        if (!method_exists($request, 'getMessages')) {
            return [];
        }

        $val = $request->getMessages();

        if (is_array($val)) {
            return $val;
        }
        if (is_object($val) && method_exists($val, 'toArray')) {
            return $val->toArray();
        }
        if ($val instanceof \Traversable) {
            return iterator_to_array($val);
        }

        return [];
    }

    private function normalizeMessage(mixed $msg): array
    {
        $role = 'user';
        $content = '';

        if (is_array($msg)) {
            $role = (string) ($msg['role'] ?? 'user');
            $content = (string) ($msg['content'] ?? '');
        } elseif (is_object($msg)) {
            $roleVal = method_exists($msg, 'getRole') ? $msg->getRole() : null;
            if ($roleVal !== null) {
                $role = is_object($roleVal) && property_exists($roleVal, 'value')
                    ? (string) $roleVal->value
                    : (string) $roleVal;
            }
            $content = method_exists($msg, 'getContent') ? (string) $msg->getContent() : '';
        }

        $role = strtolower(trim($role));
        if (!in_array($role, ['system', 'user', 'assistant'], true)) {
            $role = 'user';
        }

        return [$role, $content];
    }

    private function makeResponseMessage(string $text): AIChatResponseMessage
    {
        $roleEnumClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessageRoleEnum';
        if (!class_exists($roleEnumClass)) {
            throw new \RuntimeException('AIChatMessageRoleEnum not found (Modelflow version mismatch)');
        }

        $assistantRole = defined($roleEnumClass . '::ASSISTANT')
            ? constant($roleEnumClass . '::ASSISTANT')
            : null;

        if ($assistantRole === null) {
            throw new \RuntimeException('AIChatMessageRoleEnum::ASSISTANT missing');
        }

        $rc = new \ReflectionClass(AIChatResponseMessage::class);
        $ctor = $rc->getConstructor();
        if ($ctor === null) {
            return $rc->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();

            if ($name === 'role') {
                $args[] = $assistantRole;
                continue;
            }
            if (in_array($name, ['content', 'text', 'message'], true)) {
                $args[] = $text;
                continue;
            }

            $args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
        }

        return $rc->newInstanceArgs($args);
    }

    private function makeUsageObject(mixed $raw): mixed
    {
        $usageClass = 'ModelflowAi\\Chat\\Response\\Usage';
        if (!class_exists($usageClass)) {
            return null;
        }

        $u = $raw->usage ?? null;

        $promptTokens = is_object($u) ? ($u->promptTokens ?? $u->prompt_tokens ?? null) : null;
        $completionTokens = is_object($u) ? ($u->completionTokens ?? $u->completion_tokens ?? null) : null;
        $totalTokens = is_object($u) ? ($u->totalTokens ?? $u->total_tokens ?? null) : null;

        $inputTokens = is_object($u) ? ($u->inputTokens ?? $u->input_tokens ?? null) : null;
        $outputTokens = is_object($u) ? ($u->outputTokens ?? $u->output_tokens ?? null) : null;

        $in  = is_numeric($inputTokens) ? (int) $inputTokens : (is_numeric($promptTokens) ? (int) $promptTokens : 0);
        $out = is_numeric($outputTokens) ? (int) $outputTokens : (is_numeric($completionTokens) ? (int) $completionTokens : 0);
        $tot = is_numeric($totalTokens) ? (int) $totalTokens : ($in + $out);

        $rc = new \ReflectionClass($usageClass);
        $ctor = $rc->getConstructor();
        if ($ctor === null) {
            return $rc->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();

            if (in_array($name, ['inputTokens', 'input_tokens'], true)) {
                $args[] = $in; continue;
            }
            if (in_array($name, ['outputTokens', 'output_tokens'], true)) {
                $args[] = $out; continue;
            }
            if (in_array($name, ['totalTokens', 'total_tokens'], true)) {
                $args[] = $tot; continue;
            }

            $args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
        }

        return $rc->newInstanceArgs($args);
    }

    private function makeResponse(
        AIChatRequest $request,
        AIChatResponseMessage $message,
        mixed $raw,
        mixed $usage
    ): AIChatResponse {
        $rc = new \ReflectionClass(AIChatResponse::class);
        $ctor = $rc->getConstructor();
        if ($ctor === null) {
            return $rc->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();

            if ($name === 'request') { $args[] = $request; continue; }
            if ($name === 'message') { $args[] = $message; continue; }
            if ($name === 'raw')     { $args[] = $raw; continue; }
            if ($name === 'usage')   { $args[] = $usage; continue; }

            $args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
        }

        return $rc->newInstanceArgs($args);
    }
}
