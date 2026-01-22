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
        // ✅ genau EIN Trace pro Request
        $trace = $this->traceManager->start('http.api.chat');
        TraceContext::set($trace);

        // Optionaler UI-Span (kommt aus ChatView.vue Headern)
        $uiSpan = (string) $request->headers->get('X-UI-Span', '');
        $uiAt   = (string) $request->headers->get('X-UI-At', '');

        if ($uiSpan !== '') {
            $trace->span($uiSpan, static fn () => null, [
                'ui_at' => $uiAt !== '' ? $uiAt : null,
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
            ]);
        }

        // Optionaler UI-Span (kommt aus ChatView.vue Headern)
        $uiHttpSpan = (string) $request->headers->get('X-UI-Http-Span', '');
        $uiHttpAt   = (string) $request->headers->get('X-UI-Http-At', '');

        if ($uiHttpSpan !== '') {
            $trace->span($uiHttpSpan, static fn () => null, [
                'ui_at' => $uiHttpAt !== '' ? $uiHttpAt : null,
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
            ]);
        }

        // Controller Span und Route
        $routeName = (string) $request->attributes->get('_route', '');
        $routePath = $request->getPathInfo();

        $trace->span('controller.ChatController::chat', static fn () => null, [
            'route' => $routeName,
            'path' => $routePath,
            'method' => $request->getMethod(),
        ]);


        try {
            $payload = json_decode((string) $request->getContent(), true) ?: [];

            $sessionId = (string) ($payload['sessionId'] ?? '');
            $message = (string) ($payload['message'] ?? '');

            $dbOnly = $payload['dbOnlySolutionId'] ?? null;
            $dbOnlySolutionId = is_numeric($dbOnly) ? (int) $dbOnly : null;

            $provider = (string) ($payload['provider'] ?? 'gemini');
            $model = isset($payload['model']) && is_string($payload['model'])
                ? $payload['model']
                : null;

            $context = [];
            if (isset($payload['context']) && is_array($payload['context'])) {
                $context = $payload['context'];
            }

            // ✅ Hauptspan: support_chat.ask (alles darunter)
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

            // Response Meta
            $result['provider'] = $provider;
            if ($model !== null) {
                $result['model'] = $model;
            }

            // Trace-ID ans Frontend
            $result['trace_id'] = $trace->getTraceId();

            return new JsonResponse($result);
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }
}
