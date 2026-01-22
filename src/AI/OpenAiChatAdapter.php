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

final readonly class OpenAiChatAdapter implements AIChatAdapterInterface
{
    public function __construct(
        private Client $client,
        private string $model,
    ) {}

    public function supports(object $request): bool
    {
        return $request instanceof AIChatRequest;
    }

    public function handleRequest(object $request): AIChatResponse
    {
        /** @var Trace|null $trace */
        $trace = TraceContext::get();

        if (!$trace) {
            return $this->handleRequestNoTrace($request);
        }

        return $trace->span('adapter.openai.handleRequest', function () use ($trace, $request) {
            $meta = [
                'request_class' => is_object($request) ? $request::class : gettype($request),
                'model' => $this->model,
            ];

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

            $meta['messages_count'] = count($messages);

            $raw = $trace->span('adapter.openai.vendor_call', function () use ($messages) {
                return $this->client->chat()->create([
                    'model' => $this->model,
                    'messages' => $messages,
                ]);
            }, [
                'model' => $this->model,
                'messages_count' => count($messages),
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
        }, [
            'model' => $this->model,
        ]);
    }

    private function handleRequestNoTrace(object $request): AIChatResponse
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

        return $rc->newInstanceArgs($args);
    }
}
