<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\Chat\Request\AIChatRequest;
use ModelflowAi\Chat\Response\AIChatResponse;
use ModelflowAi\Chat\Response\AIChatResponseMessage;
use OpenAI\Client;

/**
 * OpenAiChatAdapter
 *
 * Purpose:
 * - Modelflow-compatible chat adapter that executes chat requests via the OpenAI PHP SDK.
 * - Translates Modelflow's AIChatRequest into the OpenAI "chat.completions" request format.
 * - Translates the raw OpenAI response back into Modelflow's AIChatResponse and AIChatResponseMessage.
 *
 * Responsibilities:
 * - Validate that the incoming request is an AIChatRequest.
 * - Extract messages from AIChatRequest across different Modelflow versions (arrays, collections, traversables).
 * - Normalize message roles and content into OpenAI-compatible "messages" array.
 * - Execute the OpenAI API call using the configured model.
 * - Construct Modelflow response objects (message + usage) in a version-resilient way via Reflection.
 *
 * Version compatibility:
 * - Both Modelflow and the OpenAI PHP SDK can differ in:
 *   - message object shape (arrays vs objects, enum role values, etc.)
 *   - response usage fields (prompt_tokens vs input_tokens, etc.)
 *   - constructor signatures for Modelflow response classes
 * - This adapter uses Reflection and defensive defaults to prevent nulls and mismatched ctor arguments.
 *
 * Operational notes:
 * - This adapter does not apply additional options (temperature, tools, response_format, etc.).
 *   If needed, extend handleRequest() to pass those settings from request/options/context.
 * - Concurrency and error handling are handled by upstream callers (gateway/service layer),
 *   but invalid request types raise a clear exception.
 */
final readonly class OpenAiChatAdapter implements AIChatAdapterInterface
{
    /**
     * @param Client $client OpenAI SDK client (configured with API key, base URL, etc.).
     * @param string $model  OpenAI model identifier used for chat completions.
     */
    public function __construct(
        private Client $client,
        private string $model,
    ) {}

    /**
     * Whether this adapter supports the given request object.
     *
     * @param object $request Request instance passed by Modelflow runtime.
     *
     * @return bool True if the request is an AIChatRequest.
     */
    public function supports(object $request): bool
    {
        return $request instanceof AIChatRequest;
    }

    /**
     * Execute the OpenAI chat request and return a Modelflow AIChatResponse.
     *
     * Flow:
     * 1) Validate request type.
     * 2) Extract request messages from AIChatRequest (array/collection/traversable).
     * 3) Normalize each message:
     *    - enforce roles: system|user|assistant (fallback to user)
     *    - trim content and drop empty messages
     * 4) Call OpenAI chat completions endpoint with model + messages.
     * 5) Extract the assistant text from the first choice.
     * 6) Build AIChatResponseMessage (role=ASSISTANT) and Usage object (best-effort).
     * 7) Build AIChatResponse via Reflection to match the project's Modelflow version.
     *
     * @param object $request Must be an AIChatRequest.
     *
     * @return AIChatResponse Modelflow response object containing the assistant message and usage (if available).
     *
     * @throws \InvalidArgumentException If request is not supported.
     */
    public function handleRequest(object $request): AIChatResponse
    {
        if (!$request instanceof AIChatRequest) {
            throw new \InvalidArgumentException('Unsupported request type');
        }

        $messages = [];
        foreach ($this->extractRequestMessages($request) as $msg) {
            [$role, $content] = $this->normalizeMessage($msg);
            $content = trim($content);

            if ($content === '') {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        $raw = $this->client->chat()->create([
            'model' => $this->model,
            'messages' => $messages,
        ]);

        $text = trim((string) ($raw->choices[0]->message->content ?? ''));
        if ($text === '') {
            $text = '[leere Antwort]';
        }

        $responseMessage = $this->makeResponseMessage($text);
        $usage = $this->makeUsageObject($raw);

        return $this->makeResponse(
            request: $request,
            message: $responseMessage,
            raw: $raw,
            usage: $usage,
        );
    }

    /**
     * Extract request messages from an AIChatRequest across different Modelflow versions.
     *
     * Supported shapes:
     * - getMessages() returns array
     * - getMessages() returns an object with toArray()
     * - getMessages() returns Traversable
     *
     * @param AIChatRequest $request Modelflow chat request.
     *
     * @return array<int, mixed> Raw message items (arrays or objects depending on Modelflow version).
     */
    private function extractRequestMessages(AIChatRequest $request): array
    {
        if (method_exists($request, 'getMessages')) {
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
        }

        return [];
    }

    /**
     * Normalize a Modelflow message item into an OpenAI-compatible (role, content) pair.
     *
     * Input shapes:
     * - array message: ['role' => 'user', 'content' => '...']
     * - object message: exposes getRole() and getContent()
     *
     * Role normalization:
     * - Only system|user|assistant are allowed.
     * - Unknown values fall back to "user" to prevent API errors.
     *
     * @param mixed $msg Message item from AIChatRequest.
     *
     * @return array{0:string, 1:string} Tuple: [role, content]
     */
    private function normalizeMessage(mixed $msg): array
    {
        $role = 'user';
        $content = '';

        if (is_array($msg)) {
            $role = (string) ($msg['role'] ?? 'user');
            $content = (string) ($msg['content'] ?? '');
        } elseif (is_object($msg)) {
            // Some Modelflow versions return Message objects
            $roleVal = method_exists($msg, 'getRole') ? $msg->getRole() : null;
            if ($roleVal !== null) {
                // Role can be an enum or a string-like value
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

    /**
     * Create a Modelflow AIChatResponseMessage for the assistant output text.
     *
     * Compatibility behavior:
     * - Resolves AIChatMessageRoleEnum::ASSISTANT to ensure the role is valid.
     * - Uses Reflection to match differing constructor signatures of AIChatResponseMessage.
     * - Avoids passing null role/content if the constructor expects them.
     *
     * @param string $text Assistant output text (already normalized to non-empty by caller).
     *
     * @return AIChatResponseMessage Response message instance.
     *
     * @throws \RuntimeException If Modelflow role enum cannot be resolved.
     */
    private function makeResponseMessage(string $text): AIChatResponseMessage
    {
        $roleEnumClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessageRoleEnum';
        if (!class_exists($roleEnumClass)) {
            throw new \RuntimeException('AIChatMessageRoleEnum not found (Modelflow version mismatch)');
        }

        // We want role = "assistant"
        $assistantRole = defined($roleEnumClass . '::ASSISTANT')
            ? constant($roleEnumClass . '::ASSISTANT')
            : null;

        if ($assistantRole === null) {
            throw new \RuntimeException('AIChatMessageRoleEnum::ASSISTANT missing');
        }

        // AIChatResponseMessage constructor may vary across Modelflow versions -> Reflection
        $rc = new \ReflectionClass(AIChatResponseMessage::class);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            /** @var AIChatResponseMessage */
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

        /** @var AIChatResponseMessage */
        return $rc->newInstanceArgs($args);
    }

    /**
     * Create a Modelflow Usage object from the raw OpenAI SDK response (best-effort).
     *
     * Supported raw formats (depending on OpenAI SDK / endpoint):
     * - prompt_tokens / completion_tokens / total_tokens
     * - input_tokens / output_tokens (newer formats)
     *
     * Normalization rules:
     * - Prefer input/output if present; else fall back to prompt/completion.
     * - Ensure integers and never return null token values to satisfy Modelflow constructors.
     * - If total is missing, compute total = input + output.
     *
     * @param mixed $raw Raw OpenAI SDK response object.
     *
     * @return mixed Usage object instance if available, otherwise null.
     */
    private function makeUsageObject(mixed $raw): mixed
    {
        $usageClass = 'ModelflowAi\\Chat\\Response\\Usage';
        if (!class_exists($usageClass)) {
            return null;
        }

        $u = $raw->usage ?? null;

        $promptTokens = null;
        $completionTokens = null;
        $totalTokens = null;

        $inputTokens = null;
        $outputTokens = null;

        if (is_object($u)) {
            $promptTokens = $u->promptTokens ?? $u->prompt_tokens ?? null;
            $completionTokens = $u->completionTokens ?? $u->completion_tokens ?? null;
            $totalTokens = $u->totalTokens ?? $u->total_tokens ?? null;

            $inputTokens = $u->inputTokens ?? $u->input_tokens ?? null;
            $outputTokens = $u->outputTokens ?? $u->output_tokens ?? null;
        }

        // Prefer input/output; fall back to prompt/completion.
        $in  = is_numeric($inputTokens) ? (int) $inputTokens : (is_numeric($promptTokens) ? (int) $promptTokens : 0);
        $out = is_numeric($outputTokens) ? (int) $outputTokens : (is_numeric($completionTokens) ? (int) $completionTokens : 0);
        $tot = is_numeric($totalTokens) ? (int) $totalTokens : ($in + $out);

        // Usage constructor can vary; build args by parameter name via Reflection.
        $rc = new \ReflectionClass($usageClass);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            return $rc->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();

            if (in_array($name, ['inputTokens', 'input_tokens'], true)) {
                $args[] = $in;
                continue;
            }

            if (in_array($name, ['outputTokens', 'output_tokens'], true)) {
                $args[] = $out;
                continue;
            }

            if (in_array($name, ['totalTokens', 'total_tokens'], true)) {
                $args[] = $tot;
                continue;
            }

            // For any additional parameters in newer/older versions, use default or null.
            $args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
        }

        return $rc->newInstanceArgs($args);
    }

    /**
     * Create a Modelflow AIChatResponse object in a constructor-version-resilient way.
     *
     * Uses Reflection to map parameters by name:
     * - request
     * - message
     * - raw
     * - usage
     *
     * Any other constructor parameters will receive default values (if available) or null.
     *
     * @param AIChatRequest         $request Original request object.
     * @param AIChatResponseMessage $message Response message instance.
     * @param mixed                 $raw     Raw OpenAI SDK response (attached for debugging/auditing).
     * @param mixed                 $usage   Usage object or null.
     *
     * @return AIChatResponse Constructed response object.
     */
    private function makeResponse(
        AIChatRequest $request,
        AIChatResponseMessage $message,
        mixed $raw,
        mixed $usage
    ): AIChatResponse {
        $rc = new \ReflectionClass(AIChatResponse::class);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            /** @var AIChatResponse */
            return $rc->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();

            if ($name === 'request') {
                $args[] = $request;
                continue;
            }
            if ($name === 'message') {
                $args[] = $message;
                continue;
            }
            if ($name === 'raw') {
                $args[] = $raw;
                continue;
            }
            if ($name === 'usage') {
                $args[] = $usage;
                continue;
            }

            $args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
        }

        /** @var AIChatResponse */
        return $rc->newInstanceArgs($args);
    }
}
