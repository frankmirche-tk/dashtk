<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SupportChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ChatController extends AbstractController
{
    public function __construct(
        private readonly SupportChatService $chatService,
    ) {}

    #[Route('/api/chat', name: 'app_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $payload = json_decode((string)$request->getContent(), true) ?: [];

        $sessionId = (string)($payload['sessionId'] ?? '');
        $message   = (string)($payload['message'] ?? '');

        $dbOnly = $payload['dbOnlySolutionId'] ?? null;
        $dbOnlySolutionId = is_numeric($dbOnly) ? (int)$dbOnly : null;

        $provider = (string)($payload['provider'] ?? 'gemini');
        $model = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;

        $result = $this->chatService->ask(
            sessionId: $sessionId,
            message: $message,
            dbOnlySolutionId: $dbOnlySolutionId,
            provider: $provider,
            model: $model
        );

        $result['provider'] = $provider;
        if ($model !== null) {
            $result['model'] = $model;
        }

        return new JsonResponse($result);
    }
}
