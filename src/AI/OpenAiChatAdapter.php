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
        private ?array $responseFormat = null,
        private ?float $temperature = null,
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

        $run = function () use ($request) {
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

            // optional nur wenn gesetzt
            if ($this->temperature !== null) {
                $payload['temperature'] = $this->temperature;
            }
            if ($this->responseFormat !== null) {
                $payload['response_format'] = $this->responseFormat; // z.B. ['type' => 'json_object']
            }

            $raw = $this->client->chat()->create($payload);

            $text = trim((string) ($raw->choices[0]->message->content ?? ''));
            if ($text === '') {
                $text = '[leere Antwort]';
            }

            $responseMessage = $this->makeResponseMessage($text);
            $usage = $this->makeUsageObject($raw);

            return $this->makeResponse($request, $responseMessage, $raw, $usage);
        };

        if (!$trace) {
            return $run();
        }

        return $trace->span('adapter.openai.handleRequest', function () use ($trace, $run) {
            return $trace->span('adapter.openai.vendor_call', $run, [
                'model' => $this->model,
                'response_format' => $this->responseFormat ? json_encode($this->responseFormat) : null,
                'temperature' => $this->temperature,
            ]);
        }, [
            'model' => $this->model,
        ]);
    }

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
