<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Cost\AiCostTracker;
use App\AI\Usage\AiUsage;
use App\AI\Usage\AiUsageExtractor;
use App\Tracing\TraceContext;
use ModelflowAi\Chat\Request\AIChatMessageCollection;
use ModelflowAi\Chat\Request\AIChatRequest;

final readonly class AiChatGateway
{
    public function __construct(
        private ChatAdapterRegistry $registry,
        private string $defaultProvider,
        private AiCostTracker $aiCostTracker,
        private AiUsageExtractor $aiUsageExtractor,
    ) {}

    public function chat(
        array $history,
        string $kbContext = '',
        ?string $provider = null,
        ?string $model = null,
        array $context = []
    ): string {
        return TraceContext::span('gateway.ai_chat.chat', function () use ($history, $kbContext, $provider, $model, $context) {

            $provider = $provider ? strtolower(trim($provider)) : $this->defaultProvider;

            if ($kbContext !== '') {
                $history[] = ['role' => 'system', 'content' => $kbContext];
            }

            $adapter = TraceContext::span('registry.adapter.resolve', function () use ($provider, $model, $context) {
                return $this->registry->create($provider, [
                    'model' => $model,
                    'context' => $context,
                ]);
            }, [
                'provider' => $provider,
                'model' => $model,
            ]);

            $messages = TraceContext::span('gateway.messages.build_collection', function () use ($history) {
                return $this->buildMessageCollection($history);
            }, [
                'history_count' => is_array($history) ? count($history) : null,
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

                $usageKey = $this->normalizeUsageKey((string) ($context['usage_key'] ?? 'unknown'));
                $modelUsed = $this->normalizeModel($model ?? (string) ($context['model_used'] ?? ''));

                $usage = TraceContext::span('gateway.usage.extract', function () use ($response) {
                    return $this->aiUsageExtractor->extract($response);
                });

                TraceContext::span('gateway.cost.record_ok', function () use ($usageKey, $provider, $modelUsed, $usage, $latencyMs, $context) {
                    $this->aiCostTracker->record(
                        usageKey: $usageKey,
                        provider: $provider,
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
                    'provider' => $provider,
                    'model' => $modelUsed,
                    'latency_ms' => $latencyMs,
                    'cache_hit' => (bool) ($context['cache_hit'] ?? false),
                ]);

                return TraceContext::span('gateway.response.extract_text', function () use ($response) {
                    return $this->extractResponseText($response->getMessage());
                });

            } catch (\Throwable $e) {
                $latencyMs = (int) round((microtime(true) - $start) * 1000);

                $usageKey = $this->normalizeUsageKey((string) ($context['usage_key'] ?? 'unknown'));
                $modelUsed = $this->normalizeModel($model ?? (string) ($context['model_used'] ?? ''));

                TraceContext::span('gateway.cost.record_error', function () use ($usageKey, $provider, $modelUsed, $latencyMs, $e, $context) {
                    $this->aiCostTracker->record(
                        usageKey: $usageKey,
                        provider: $provider,
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
                    'provider' => $provider,
                    'model' => $modelUsed,
                    'latency_ms' => $latencyMs,
                    'error' => $e::class,
                ]);

                throw $e;
            }

        }, [
            'provider' => $provider ? strtolower(trim($provider)) : $this->defaultProvider,
            'model' => $model,
        ]);
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
            return [
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
}
