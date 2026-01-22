<?php

declare(strict_types=1);

namespace App\AI;

use App\Tracing\Trace;
use App\Tracing\TraceContext;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\Chat\Request\AIChatRequest;
use ModelflowAi\Chat\Response\AIChatResponse;

final readonly class GeminiChatAdapter implements AIChatAdapterInterface
{
    public function __construct(
        private AIChatAdapterInterface $inner,
    ) {}

    public function supports(object $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handleRequest(object $request): AIChatResponse
    {
        /** @var Trace|null $trace */
        $trace = TraceContext::get();

        if (!$trace) {
            return $this->inner->handleRequest($request);
        }

        return $trace->span('adapter.gemini.handleRequest', function () use ($trace, $request) {
            $meta = [
                'request_class' => is_object($request) ? $request::class : gettype($request),
            ];

            if ($request instanceof AIChatRequest && method_exists($request, 'getMessages')) {
                $msgs = $request->getMessages();
                $meta['messages_type'] = is_object($msgs) ? $msgs::class : gettype($msgs);
                $meta['messages_count'] = is_array($msgs) ? count($msgs) : ($msgs instanceof \Countable ? count($msgs) : null);
            }

            return $trace->span('adapter.gemini.vendor_call', function () use ($request) {
                return $this->inner->handleRequest($request);
            }, $meta);

        }, [
            'inner_class' => $this->inner::class,
        ]);
    }
}
