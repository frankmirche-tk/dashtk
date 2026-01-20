<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Cost\AiCostTracker;
use App\AI\Usage\AiUsage;
use App\AI\Usage\AiUsageExtractor;
use ModelflowAi\Chat\Request\AIChatMessageCollection;
use ModelflowAi\Chat\Request\AIChatRequest;

/**
 * AiChatGateway
 *
 * Purpose:
 * - Provides a single, provider-agnostic entry point for executing chat requests.
 * - Translates a simple "history array" format into Modelflow's typed request objects.
 * - Appends an optional knowledge-base context block (kbContext) as an additional system message.
 * - Delegates the actual provider execution to a provider-specific adapter obtained via ChatAdapterRegistry.
 *
 * Responsibilities:
 * - Normalize provider selection (explicit provider or default provider).
 * - Convert history messages (role/content arrays) into AIChatMessageCollection.
 * - Build an AIChatRequest compatible with the project's Modelflow version.
 * - Execute the request through the chosen adapter and extract a plain text response.
 *
 * Inputs:
 * - $history: list of messages in an OpenAI-like format:
 *   [
 *     ['role' => 'system'|'user'|'assistant', 'content' => '...'],
 *     ...
 *   ]
 * - $kbContext: optional additional "system" message appended at the end of history.
 * - $context: optional provider-specific structured context passed to the adapter registry.
 *
 * AI cost measurement (Tokens + Requests):
 * - This gateway is the central, provider-agnostic place to measure AI usage and costs.
 * - Measurement is explicit (no magic) and uses context-based attribution (Variant B):
 *   - $context['usage_key'] is used to attribute requests/tokens/costs to a feature/entry-point.
 * - The tracker writes aggregated daily counters to the cache, so reports can be generated quickly.
 *
 * Suggested context keys (Variant B):
 * - usage_key: string  (e.g. "support_chat.ask")  -> required for meaningful attribution
 * - cache_hit: bool    (true if your own prompt/response cache returned a hit)
 * - model_used: string (optional override if the effective model differs from $model argument)
 *
 * Version compatibility:
 * - Modelflow's message and request classes have changed across versions.
 * - This gateway contains defensive logic for:
 *   - typed message objects (AIChatMessage, AIChatMessageRoleEnum)
 *   - request constructor signatures (AIChatRequest requires multiple arguments)
 * - The fallback behavior aims to prevent null/invalid role values (common source of runtime errors).
 */
final readonly class AiChatGateway
{
    /**
     * @param ChatAdapterRegistry $registry        Factory/registry that creates provider adapters.
     * @param string              $defaultProvider Default provider key used when chat() is called without $provider.
     * @param AiCostTracker       $aiCostTracker   Explicit AI cost/usage tracker (requests + tokens + optional EUR cost).
     * @param AiUsageExtractor    $aiUsageExtractor Best-effort extractor for usage metrics from provider responses.
     */
    public function __construct(
        private ChatAdapterRegistry $registry,
        private string $defaultProvider,
        private AiCostTracker $aiCostTracker,
        private AiUsageExtractor $aiUsageExtractor,
    ) {}

    /**
     * Execute a chat request against the selected provider and return the assistant text.
     *
     * Behavior:
     * - Provider selection:
     *   - If $provider is null/empty, uses $defaultProvider.
     *   - Provider keys are normalized to lowercase.
     * - KB context injection:
     *   - If $kbContext is not empty, it is appended as an additional "system" message at the end of history.
     *   - This makes it easy to pass SOP/KB hints without mutating the original session prompt.
     * - Adapter resolution:
     *   - Uses ChatAdapterRegistry::create($provider, ...) to instantiate the provider adapter.
     *   - The options array contains:
     *     - model: optional model override
     *     - context: provider-specific structured context
     * - AI cost measurement (Tokens + Requests):
     *   - Measures latency, success/error and extracts usage tokens (best-effort).
     *   - Attributes the request via $context['usage_key'] (Variant B).
     *   - Records an aggregated event via AiCostTracker (typically daily aggregates in cache).
     *
     * @param array<int, array{role:string, content:string}> $history  Chat history in OpenAI-like array format.
     * @param string                                        $kbContext Optional KB context appended as a system message.
     * @param string|null                                   $provider  Provider key (e.g. "gemini", "openai"); defaults to configured default provider.
     * @param string|null                                   $model     Optional provider model override.
     * @param array                                         $context   Additional provider-specific context forwarded to the adapter.
     *
     * @return string Plain text assistant response (best-effort extraction).
     *
     * @throws \Throwable Re-throws provider/adapter exceptions after recording an error event.
     */
    public function chat(
        array $history,
        string $kbContext = '',
        ?string $provider = null,
        ?string $model = null,
        array $context = []
    ): string {
        $provider = $provider ? strtolower(trim($provider)) : $this->defaultProvider;

        if ($kbContext !== '') {
            $history[] = ['role' => 'system', 'content' => $kbContext];
        }

        $adapter = $this->registry->create($provider, [
            'model' => $model,
            'context' => $context,
        ]);

        $messages = $this->buildMessageCollection($history);

        $request = $this->makeAiChatRequest($messages);

        // ---- AI Cost / Usage instrumentation (explicit; Variant B via $context['usage_key']) ----
        // We track:
        // - requests (always 1 per gateway call)
        // - tokens (best-effort via AiUsageExtractor)
        // - latency (ms)
        // - success/error
        // - optional cache-hit flag (if the caller sets it)
        //
        // Attribution:
        // - usage_key is expected in $context['usage_key'] and should match your business entry-point key.
        // - If missing, we record under "unknown" so reporting still works and highlights missing attribution.
        $start = microtime(true);

        try {
            $response = $adapter->handleRequest($request);

            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            // Variant B: feature/entry-point attribution via context
            $usageKey = $this->normalizeUsageKey((string) ($context['usage_key'] ?? 'unknown'));

            // Determine effective model (best-effort):
            // - prefer explicit $model argument
            // - allow override from context if your adapter selects a different model internally
            $modelUsed = $this->normalizeModel(
                $model ?? (string) ($context['model_used'] ?? '')
            );

            // Extract tokens usage from response (best-effort, provider-agnostic).
            $usage = $this->aiUsageExtractor->extract($response);

            // Record success event (aggregated in cache).
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

            return $this->extractResponseText($response->getMessage());
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            $usageKey = $this->normalizeUsageKey((string) ($context['usage_key'] ?? 'unknown'));
            $modelUsed = $this->normalizeModel(
                $model ?? (string) ($context['model_used'] ?? '')
            );

            // Record error event (tokens usually unknown on hard failures).
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

            throw $e;
        }
    }

    /**
     * Convert a history array into a Modelflow AIChatMessageCollection.
     *
     * Notes:
     * - The collection expects AIChatMessage objects (in the project's Modelflow version),
     *   not raw arrays.
     * - Messages with empty content are skipped defensively.
     * - Roles are normalized to lowercase. Unknown roles fall back to "user".
     *
     * @param array<int, array{role:string, content:string}> $history History list.
     *
     * @return AIChatMessageCollection Typed message collection for AIChatRequest.
     */
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

    /**
     * Build a single Modelflow-compatible chat message object.
     *
     * Compatibility behavior:
     * - Preferred path: instantiate Modelflow's AIChatMessage with AIChatMessageRoleEnum.
     * - Fallback: return an array-shaped message (should normally not occur, but prevents hard crashes
     *   if Modelflow classes are missing in runtime).
     *
     * @param string $role    Message role ("system", "user", "assistant").
     * @param string $content Message content (non-empty).
     *
     * @return object An AIChatMessage instance (or array fallback wrapped as object-like return type).
     */
    private function makeAiChatMessage(string $role, string $content): object
    {
        // These classes are expected to exist for the project's Modelflow version.
        $msgClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessage';
        $roleEnumClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessageRoleEnum';

        if (!class_exists($msgClass) || !class_exists($roleEnumClass)) {
            // Fallback (should not happen in the intended environment)
            return [
                'role' => $role,
                'content' => $content,
            ];
        }

        /** @var class-string $roleEnumClass */
        $roleEnum = $this->mapRoleEnum($roleEnumClass, $role);

        // Constructor signature: (roleEnum, content)
        return new $msgClass($roleEnum, $content);
    }

    /**
     * Map a normalized role string to Modelflow's role enum instance.
     *
     * Rationale:
     * - Prevents null/invalid role values that can trigger runtime errors in Modelflow.
     * - Ensures the resulting value is always a valid enum member.
     *
     * Mapping:
     * - "system"    -> SYSTEM
     * - "assistant" -> ASSISTANT
     * - default     -> USER
     *
     * @param string $roleEnumClass Fully qualified enum class name.
     * @param string $role          Normalized role string.
     *
     * @return object Enum instance (backed enum case).
     *
     * @throws \RuntimeException If no usable enum case can be resolved.
     */
    private function mapRoleEnum(string $roleEnumClass, string $role): object
    {
        $role = strtolower($role);

        $wanted = match ($role) {
            'system' => 'SYSTEM',
            'assistant' => 'ASSISTANT',
            default => 'USER',
        };

        // PHP 8.1+ enums: ::SYSTEM, ::USER, ::ASSISTANT
        if (defined($roleEnumClass . '::' . $wanted)) {
            return constant($roleEnumClass . '::' . $wanted);
        }

        // Fallback to USER if possible
        if (defined($roleEnumClass . '::USER')) {
            return constant($roleEnumClass . '::USER');
        }

        // Hard failure: avoids passing null and causing opaque downstream errors
        throw new \RuntimeException('AIChatMessageRoleEnum not resolvable');
    }

    /**
     * Extract a plain text response from the provider response message.
     *
     * Compatibility behavior:
     * - If message is already a string: return it.
     * - If message is an object:
     *   - Prefer getContent()
     *   - Else read public property "content"
     *   - Else use __toString()
     * - If no readable content can be extracted: return a stable placeholder.
     *
     * @param mixed $message Provider response message (string or object).
     *
     * @return string Extracted response text (trimmed).
     */
    private function extractResponseText(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        // In this project the message is typically an AIChatResponseMessage object.
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

    /**
     * Build an AIChatRequest instance compatible with the Modelflow version used in this project.
     *
     * Implementation details:
     * - Modelflow's AIChatRequest constructor (in the observed version) expects multiple parameters,
     *   including criteria, tool metadata, a callable request handler, and tool-choice configuration.
     * - This gateway provides minimal "no-op" defaults:
     *   - empty criteria collection
     *   - no tools / tool infos
     *   - empty options / metadata
     *   - a no-op callable request handler
     *   - tool choice defaults to AUTO if available
     *
     * @param AIChatMessageCollection $messages Typed chat messages collection.
     *
     * @return AIChatRequest Fully constructed request object ready for adapter execution.
     *
     * @throws \RuntimeException If required Modelflow classes are not available at runtime.
     */
    private function makeAiChatRequest(AIChatMessageCollection $messages): AIChatRequest
    {
        $criteriaClass = 'ModelflowAi\\DecisionTree\\Criteria\\CriteriaCollection';
        if (!class_exists($criteriaClass)) {
            throw new \RuntimeException('CriteriaCollection class not found: ' . $criteriaClass);
        }

        $criteria = new $criteriaClass();

        // Tools, toolInfos and options are optional here; empty arrays are valid defaults.
        $tools = [];
        $toolInfos = [];
        $options = [];

        // Request handler must be callable; minimal no-op handler is sufficient.
        $requestHandler = static function (): void {};

        // Optional metadata and responseFormat
        $metadata = [];
        $responseFormat = null;

        // ToolChoice enum: prefer AUTO case if present, otherwise fallback to "auto" string.
        $toolChoiceEnumClass = 'ModelflowAi\\Chat\\ToolInfo\\ToolChoiceEnum';
        $toolChoice = defined($toolChoiceEnumClass . '::AUTO')
            ? constant($toolChoiceEnumClass . '::AUTO')
            : (defined($toolChoiceEnumClass . '::auto') ? constant($toolChoiceEnumClass . '::auto') : 'auto');

        // Constructor signature (as per reflection in the project):
        // __construct(
        //   AIChatMessageCollection $messages,
        //   CriteriaCollection $criteria,
        //   array $tools,
        //   array $toolInfos,
        //   array $options,
        //   callable $requestHandler,
        //   array $metadata = [],
        //   ?ResponseFormatInterface $responseFormat = null,
        //   ToolChoiceEnum $toolChoice = "auto"
        // )
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

    /**
     * Normalize a usage key for cost attribution.
     *
     * Notes:
     * - Keeps a stable key even if callers pass empty/whitespace.
     * - Uses "unknown" as a safe fallback, so missing attribution becomes visible in reports.
     */
    private function normalizeUsageKey(string $usageKey): string
    {
        $usageKey = trim($usageKey);
        return $usageKey !== '' ? $usageKey : 'unknown';
    }

    /**
     * Normalize model name for reporting and pricing lookup.
     *
     * Notes:
     * - Providers sometimes return/alias different model names than requested.
     * - If you know the effective model in your adapter, you can pass it via $context['model_used'].
     * - "unknown" is used if nothing is provided.
     */
    private function normalizeModel(?string $model): string
    {
        $model = trim((string) $model);
        return $model !== '' ? $model : 'unknown';
    }

    /**
     * Normalize an error code string for aggregation.
     *
     * Notes:
     * - We prefer a numeric exception code if present.
     * - Otherwise we fall back to the exception class name (stable enough for aggregation).
     */
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
