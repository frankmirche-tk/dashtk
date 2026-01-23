<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SupportChatService;
use App\Tracing\TraceContext;
use App\Tracing\TraceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ChatController extends AbstractController
{
    public function __construct(
        private readonly SupportChatService $chatService,
        private readonly TraceManager $traceManager,
    ) {}

    #[Route('/api/chat', name: 'app_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat');
        TraceContext::set($trace);

        // UI span
        $uiSpan = (string) $request->headers->get('X-UI-Span', '');
        $uiAt   = (string) $request->headers->get('X-UI-At', '');

        if ($uiSpan !== '') {
            $trace->span($uiSpan, static fn () => null, [
                'at' => $uiAt !== '' ? $uiAt : null,
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
            ]);
        }

        // axios/http span from UI
        $uiHttpSpan = (string) $request->headers->get('X-UI-Http-Span', '');
        $uiHttpAt   = (string) $request->headers->get('X-UI-Http-At', '');

        if ($uiHttpSpan !== '') {
            $trace->span($uiHttpSpan, static fn () => null, [
                'at' => $uiHttpAt !== '' ? $uiHttpAt : null,
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
            ]);
        }

        // controller node (server fact)
        $routeName = (string) $request->attributes->get('_route', '');
        $trace->span('controller.ChatController::chat', static fn () => null, [
            'route' => $routeName,
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
        ]);

        try {
            $payload = json_decode((string) $request->getContent(), true) ?: [];

            $sessionId = (string) ($payload['sessionId'] ?? '');
            $message = (string) ($payload['message'] ?? '');

            $dbOnly = $payload['dbOnlySolutionId'] ?? null;
            $dbOnlySolutionId = is_numeric($dbOnly) ? (int) $dbOnly : null;

            $provider = (string) ($payload['provider'] ?? 'gemini');
            $model = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;

            $context = (isset($payload['context']) && is_array($payload['context'])) ? $payload['context'] : [];

            // Wrapper span: everything below will become children automatically
            $result = $trace->span('support_chat.ask', function () use (
                $sessionId,
                $message,
                $dbOnlySolutionId,
                $provider,
                $model,
                $context,
                $trace
            ) {
                return $this->chatService->ask(
                    sessionId: $sessionId,
                    message: $message,
                    dbOnlySolutionId: $dbOnlySolutionId,
                    provider: $provider,
                    model: $model,
                    context: $context,
                    trace: $trace,
                );
            }, [
                'provider' => $provider,
                'model' => $model,
                'db_only' => $dbOnlySolutionId !== null,
            ]);

            $result['provider'] = $provider;
            if ($model !== null) $result['model'] = $model;
            $result['trace_id'] = $trace->getTraceId();

            return new JsonResponse($result);
        } finally {
            TraceContext::set(null);
            //$this->traceManager->saveAndClose($trace);
            $trace->finish();
        }
    }
}
