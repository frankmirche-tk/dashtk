<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Cost\AiCostTracker;
use App\AI\Usage\AiUsage;
use App\AI\Usage\AiUsageExtractor;
use App\Tracing\TraceContext;
use ModelflowAi\Chat\Request\AIChatMessageCollection;
use ModelflowAi\Chat\Request\AIChatRequest;
use Psr\Log\LoggerInterface;

final readonly class AiChatGateway
{
    public function __construct(
        private ChatAdapterRegistry $registry,
        private string $defaultProvider,
        private AiCostTracker $aiCostTracker,
        private AiUsageExtractor $aiUsageExtractor,
        private ?LoggerInterface $logger = null,

        /**
         * Schutz vor "History-Explosion".
         * Empfehlung: 40–80 (je nach Systemprompt-Länge & Use-Case).
         */
        private int $maxMessages = 60,

        /**
         * Warnschwelle: ab welcher KB_CONTEXT Länge wir ein Warning loggen.
         * (Nur Länge, kein Inhalt.)
         */
        private int $warnKbContextLen = 18_000,
    ) {}

    public function chat(
        array $history,
        string $kbContext = '',
        ?string $provider = null,
        ?string $model = null,
        array $context = []
    ): string {

        // --- Provider-Routing (override) ---
        // 1) letzte User-Message aus dem Verlauf ziehen
        $userMessage = $this->extractLastUserMessage($history);

        // 2) optionale Routing-Hints aus context (wenn du sie übergibst)
        $modeHint   = (string)($context['mode_hint'] ?? '');
        $kbMatches  = (array)($context['kb_matches'] ?? []);

        // 3) Nur dann overriden, wenn der Caller NICHT explizit provider vorgibt
        //    (wenn du IMMER erzwingen willst: diese if-Klammer entfernen)
        $providerWasAuto = ($provider === null || $provider === '');
        if ($providerWasAuto) {
            $provider = $this->pickProvider($userMessage, $modeHint, $kbMatches, $kbContext);
        }
        // --- /Provider-Routing ---

        $providerUsed = $this->normalizeProvider($provider ?: $this->defaultProvider);
        $modelWanted  = $this->normalizeModel($model);
        $usageKey     = $this->normalizeUsageKey((string) ($context['usage_key'] ?? 'unknown'));

        return TraceContext::span('gateway.ai_chat.chat', function () use ($history, $kbContext, $providerUsed, $modelWanted, $usageKey, $context, $providerWasAuto) {

            $kbContextLen = mb_strlen($kbContext);
            if ($kbContext !== '') {
                $history[] = ['role' => 'system', 'content' => $kbContext];
            }

            // Normalize + limit
            $history = $this->normalizeHistory($history);

            $historyCountBeforeLimit = count($history);
            if ($historyCountBeforeLimit > $this->maxMessages) {
                $history = array_slice($history, -$this->maxMessages);
            }

            // Structured meta (ohne Inhalte)
            $meta = [
                'provider' => $providerUsed,
                'model_wanted' => $modelWanted,
                'usage_key' => $usageKey,
                'history_count' => count($history),
                'history_count_before_limit' => $historyCountBeforeLimit,
                'max_messages' => $this->maxMessages,
                'kb_context_len' => $kbContextLen,
                'cache_hit' => (bool) ($context['cache_hit'] ?? false),
                'roles' => $this->summarizeRoles($history),
                'content_lens' => $this->summarizeContentLens($history),
            ];

            $this->logInfo('ai.gateway.request_built', $meta);

            // Warnungen bei auffälligen Inputs (in PROD sichtbar, wenn Handler >= warning)
            if ($historyCountBeforeLimit > $this->maxMessages) {
                $this->logWarning('ai.gateway.history_truncated', $meta);
            }
            if ($kbContextLen >= $this->warnKbContextLen) {
                $this->logWarning('ai.gateway.kb_context_very_large', $meta);
            }

            $adapter = TraceContext::span('registry.adapter.resolve', function () use ($providerUsed, $modelWanted, $context) {
                return $this->registry->create($providerUsed, [
                    'model' => $modelWanted !== 'unknown' ? $modelWanted : null,
                    'context' => $context,
                ]);
            }, [
                'provider' => $providerUsed,
                'model' => $modelWanted !== 'unknown' ? $modelWanted : null,
            ]);

            $messages = TraceContext::span('gateway.messages.build_collection', function () use ($history) {
                return $this->buildMessageCollection($history);
            }, [
                'history_count' => count($history),
            ]);

            $request = TraceContext::span('gateway.request.make_ai_chat_request', function () use ($messages) {
                return $this->makeAiChatRequest($messages);
            });

            $start = microtime(true);

            try {
                $response = TraceContext::span('adapter.handle_request', function () use ($adapter, $request) {
                    return $adapter->handleRequest($request);
                }, [
                    'adapter_class' => is_object($adapter) ? $adapter::class : gettype($adapter),
                ]);

                $latencyMs = (int) round((microtime(true) - $start) * 1000);

                $usage = TraceContext::span('gateway.usage.extract', function () use ($response) {
                    return $this->aiUsageExtractor->extract($response);
                });

                // model_used -> context > wanted > unknown
                $modelUsed = $this->normalizeModel((string) ($context['model_used'] ?? ''));
                if ($modelUsed === 'unknown' && $modelWanted !== 'unknown') {
                    $modelUsed = $modelWanted;
                }

                TraceContext::span('gateway.cost.record_ok', function () use ($usageKey, $providerUsed, $modelUsed, $usage, $latencyMs, $context) {
                    $this->aiCostTracker->record(
                        usageKey: $usageKey,
                        provider: $providerUsed,
                        model: $modelUsed,
                        usage: $usage,
                        latencyMs: $latencyMs,
                        ok: true,
                        errorCode: null,
                        cacheHit: (bool) ($context['cache_hit'] ?? false),
                    );
                    return null;
                }, [
                    'usage_key' => $usageKey,
                    'provider' => $providerUsed,
                    'model' => $modelUsed,
                    'latency_ms' => $latencyMs,
                    'cache_hit' => (bool) ($context['cache_hit'] ?? false),
                ]);

                $text = TraceContext::span('gateway.response.extract_text', function () use ($response) {
                    return $this->extractResponseText($response->getMessage());
                });

                // --- Meta-Leak-Filter (Gemini leakt sonst gerne "Denke Schritt für Schritt", Prompt/Policy etc.) ---
                $text = $this->stripMetaLeaks($text);

                // Leere/Platzhalter-Antwort -> warning
                if (trim($text) === '' || $text === '[leere Antwort]' || $text === '[unlesbare Antwort]') {
                    $this->logWarning('ai.gateway.suspicious_empty_response', [
                        'provider' => $providerUsed,
                        'model' => $modelUsed,
                        'usage_key' => $usageKey,
                        'latency_ms' => $latencyMs,
                    ]);
                } else {
                    $this->logInfo('ai.gateway.ok', [
                        'provider' => $providerUsed,
                        'model' => $modelUsed,
                        'usage_key' => $usageKey,
                        'latency_ms' => $latencyMs,
                        'cache_hit' => (bool) ($context['cache_hit'] ?? false),
                    ]);
                }

                return $text;

            } catch (\Throwable $e) {
                $latencyMs = (int) round((microtime(true) - $start) * 1000);

                // --- GRÜN: Gemini -> Auto-Fallback auf OpenAI bei Safety ODER transienten Vendor/Netzwerkfehlern ---
                // Wichtig: nur EINMAL fallbacken (sonst Endlosschleife)
                $alreadyFallbacked = (bool)($context['gemini_fallback_done'] ?? false);
                $isGeminiProvider  = ($providerUsed === 'gemini');
                $msg = trim((string)$e->getMessage());

                // 1) Safety block aus Adapter (dein GeminiChatAdapter wirft exakt diese Message)
                $isGeminiSafety = ($e instanceof \RuntimeException) && ($msg === 'GEMINI_SAFETY_BLOCKED');

                // 2) Transiente Transport-/HTTP-/Stream-Probleme (z.B. "Connection reset by peer")
                //    -> bewusst "fuzzy" matchen, weil Vendor/HTTP-Stack variiert.
                $isTransientGemini = (
                        str_contains($msg, 'Unable to read stream contents')
                        || str_contains($msg, 'Connection reset by peer')
                        || str_contains($msg, 'cURL error')
                        || str_contains($msg, 'Operation timed out')
                        || str_contains($msg, 'timeout')
                        || str_contains($msg, 'HTTP 429')
                        || str_contains($msg, 'Too Many Requests')
                        || str_contains($msg, '503')
                        || str_contains($msg, 'Service Unavailable')
                    );

                // Fallback nur wenn:
                // - wir gerade Gemini benutzen
                // - noch nicht gefallbackt
                // - und der Caller "auto" war (also provider nicht explizit auf gemini gesetzt)
                if ($isGeminiProvider && !$alreadyFallbacked && ($isGeminiSafety || $isTransientGemini) && ($providerWasAuto ?? false)) {
                    $context['gemini_fallback_done'] = true;

                    $this->logWarning('ai.gateway.gemini_fallback_to_openai', [
                        'provider' => $providerUsed,
                        'usage_key' => $usageKey,
                        'latency_ms' => $latencyMs,
                        'kb_context_len' => $kbContextLen,
                        'reason' => $isGeminiSafety ? 'gemini_safety' : 'gemini_transient',
                        'error_snippet' => $this->makeSafeShortDetail($msg),
                    ]);

                    return $this->chat(
                        history: $history,
                        kbContext: '',
                        provider: 'openai',
                        model: null,
                        context: $context
                    );
                }
        // --- /GRÜN ---

                $modelUsed = $this->normalizeModel((string) ($context['model_used'] ?? ''));
                if ($modelUsed === 'unknown' && $modelWanted !== 'unknown') {
                    $modelUsed = $modelWanted;
                }

                TraceContext::span('gateway.cost.record_error', function () use ($usageKey, $providerUsed, $modelUsed, $latencyMs, $e, $context) {
                    $this->aiCostTracker->record(
                        usageKey: $usageKey,
                        provider: $providerUsed,
                        model: $modelUsed,
                        usage: new AiUsage(null, null, null),
                        latencyMs: $latencyMs,
                        ok: false,
                        errorCode: $this->normalizeErrorCode($e),
                        cacheHit: (bool) ($context['cache_hit'] ?? false),
                    );
                    return null;
                }, [
                    'usage_key' => $usageKey,
                    'provider' => $providerUsed,
                    'model' => $modelUsed,
                    'latency_ms' => $latencyMs,
                    'error' => $e::class,
                ]);

                $this->logError('ai.gateway.error', [
                    'provider' => $providerUsed,
                    'model' => $modelUsed,
                    'usage_key' => $usageKey,
                    'latency_ms' => $latencyMs,
                    'cache_hit' => (bool) ($context['cache_hit'] ?? false),
                    'error_class' => $e::class,
                    'error_code' => $this->normalizeErrorCode($e),
                    'error_snippet' => $this->makeSafeShortDetail($e->getMessage()),
                    'history_count' => count($history),
                    'kb_context_len' => $kbContextLen,
                ]);

                throw $e;
            }

        }, [
            'provider' => $providerUsed,
            'model' => $modelWanted !== 'unknown' ? $modelWanted : null,
        ]);
    }

    private function normalizeHistory(array $history): array
    {
        $out = [];

        foreach ($history as $m) {
            if (!is_array($m)) {
                continue;
            }

            $role = strtolower(trim((string) ($m['role'] ?? 'user')));
            $content = (string) ($m['content'] ?? '');

            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }

            $content = trim($content);
            if ($content === '') {
                continue;
            }

            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out;
    }

    private function buildMessageCollection(array $history): AIChatMessageCollection
    {
        $messages = new AIChatMessageCollection();

        foreach ($history as $m) {
            $role = strtolower(trim((string) ($m['role'] ?? 'user')));
            $content = trim((string) ($m['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $messages->append($this->makeAiChatMessage($role, $content));
        }

        return $messages;
    }

    private function makeAiChatMessage(string $role, string $content): object
    {
        $msgClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessage';
        $roleEnumClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessageRoleEnum';

        if (!class_exists($msgClass) || !class_exists($roleEnumClass)) {
            return (object) [
                'role' => $role,
                'content' => $content,
            ];
        }

        $roleEnum = $this->mapRoleEnum($roleEnumClass, $role);

        return new $msgClass($roleEnum, $content);
    }

    private function mapRoleEnum(string $roleEnumClass, string $role): object
    {
        $role = strtolower($role);

        $wanted = match ($role) {
            'system' => 'SYSTEM',
            'assistant' => 'ASSISTANT',
            default => 'USER',
        };

        if (defined($roleEnumClass . '::' . $wanted)) {
            return constant($roleEnumClass . '::' . $wanted);
        }

        if (defined($roleEnumClass . '::USER')) {
            return constant($roleEnumClass . '::USER');
        }

        throw new \RuntimeException('AIChatMessageRoleEnum not resolvable');
    }

    private function extractResponseText(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_object($message)) {
            if (method_exists($message, 'getContent')) {
                return trim((string) $message->getContent());
            }
            if (property_exists($message, 'content')) {
                return trim((string) $message->content);
            }
            if (method_exists($message, '__toString')) {
                return trim((string) $message);
            }
        }

        return '[unlesbare Antwort]';
    }

    private function stripMetaLeaks(string $text): string
    {
        $patterns = [
            // Klassiker: "Denke Schritt für Schritt:"
            '~(?is)\bdenke\s+schritt\s+f[üu]r\s+schritt\s*:.*?(?=\n\s*\n|$)~u',

            // Policy/Prompt leaks
            '~(?is)\b(prompt_id|kb_context|anweisung|policy|system[- ]prompt|interne\s+regeln)\b\s*:.*?(?=\n\s*\n|$)~u',

            // "Analyse der Nutzeranfrage:" etc.
            '~(?is)\b(analyse\s+der\s+nutzeranfrage|pr[üu]fung\s+des\s+kb_context|formulierung\s+der\s+antwort)\b\s*:.*?(?=\n\s*\n|$)~u',
        ];

        $clean = preg_replace($patterns, '', $text);
        $clean = trim((string)$clean);

        // Falls alles weggeschnitten wurde: fallback
        if ($clean === '') {
            return "Ich habe dazu einen passenden SOP-Eintrag gefunden. Meinst du die Druckerwarteschlange (Windows) oder eine Warteschlange in Advarics/Advarics-Cash? Wenn du mir kurz sagst: welcher Drucker und welche Fehlermeldung, kann ich dir die richtigen Schritte geben.";
        }

        return $clean;
    }



    private function makeAiChatRequest(AIChatMessageCollection $messages): AIChatRequest
    {
        $criteriaClass = 'ModelflowAi\\DecisionTree\\Criteria\\CriteriaCollection';
        if (!class_exists($criteriaClass)) {
            throw new \RuntimeException('CriteriaCollection class not found: ' . $criteriaClass);
        }

        $criteria = new $criteriaClass();
        $tools = [];
        $toolInfos = [];
        $options = [];
        $requestHandler = static function (): void {};
        $metadata = [];
        $responseFormat = null;

        $toolChoiceEnumClass = 'ModelflowAi\\Chat\\ToolInfo\\ToolChoiceEnum';
        $toolChoice = defined($toolChoiceEnumClass . '::AUTO')
            ? constant($toolChoiceEnumClass . '::AUTO')
            : (defined($toolChoiceEnumClass . '::auto') ? constant($toolChoiceEnumClass . '::auto') : 'auto');

        return new AIChatRequest(
            $messages,
            $criteria,
            $tools,
            $toolInfos,
            $options,
            $requestHandler,
            $metadata,
            $responseFormat,
            $toolChoice
        );
    }

    private function normalizeUsageKey(string $usageKey): string
    {
        $usageKey = trim($usageKey);
        return $usageKey !== '' ? $usageKey : 'unknown';
    }

    private function normalizeModel(?string $model): string
    {
        $model = trim((string) $model);
        return $model !== '' ? $model : 'unknown';
    }

    private function normalizeProvider(string $provider): string
    {
        $p = strtolower(trim($provider));
        return $p !== '' ? $p : 'unknown';
    }

    private function normalizeErrorCode(\Throwable $e): string
    {
        $code = $e->getCode();
        if (is_int($code) && $code !== 0) {
            return (string) $code;
        }
        if (is_string($code) && $code !== '' && $code !== '0') {
            return $code;
        }

        return $e::class;
    }

    private function summarizeRoles(array $history): array
    {
        $roles = [];
        foreach ($history as $m) {
            $roles[] = (string) ($m['role'] ?? 'unknown');
        }
        return $roles;
    }

    private function summarizeContentLens(array $history): array
    {
        $lens = [];
        foreach ($history as $m) {
            $c = (string) ($m['content'] ?? '');
            $lens[] = $c !== '' ? mb_strlen($c) : 0;
        }
        return $lens;
    }

    private function makeSafeShortDetail(string $raw): string
    {
        $detail = trim($raw);
        if ($detail === '') {
            return '';
        }

        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        if (mb_strlen($detail) > 260) {
            $detail = mb_substr($detail, 0, 260) . '…';
        }

        return $detail;
    }

    private function logInfo(string $event, array $context): void
    {
        if ($this->logger) {
            $this->logger->info($event, $context);
        }
    }

    private function logWarning(string $event, array $context): void
    {
        if ($this->logger) {
            $this->logger->warning($event, $context);
        }
    }

    private function logError(string $event, array $context): void
    {
        if ($this->logger) {
            $this->logger->error($event, $context);
        }
    }

    private function pickProvider(string $userMessage, string $modeHint, array $kbMatches, string $kbContext): string
    {
        // 1) Newsletter explizit? -> Gemini darf
        $msg = mb_strtolower($userMessage);
        $looksLikeNewsletter = str_contains($msg, 'newsletter')
            || str_contains($msg, 'kw')
            || preg_match('~seit\s+\d{1,2}\.\d{1,2}\.\d{4}~u', $msg);

        // 2) SOP/Technik? -> OpenAI erzwingen (weil Gemini häufiger meta-leakt)
        $hasSopHit = false;
        foreach ($kbMatches as $m) {
            $type = (string)($m['type'] ?? '');
            if ($type === 'SOP') { $hasSopHit = true; break; }
        }

        $looksTechnical = preg_match('~\b(warteschlange|drucker|kasse|login|bondrucker|ec|teamviewer|wireguard|server)\b~u', $msg) === 1;

        if ($hasSopHit || $looksTechnical) {
            return 'openai';
        }

        if ($looksLikeNewsletter) {
            return 'gemini';
        }

        // default
        return 'openai';
    }

    private function extractLastUserMessage(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $msg = $history[$i] ?? null;
            if (!is_array($msg)) {
                continue;
            }
            $role = (string)($msg['role'] ?? '');
            if ($role === 'user') {
                return (string)($msg['content'] ?? '');
            }
        }
        return '';
    }


}
