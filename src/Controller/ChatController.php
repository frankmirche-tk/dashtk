<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SupportChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * ChatController
 *
 * Purpose:
 * - HTTP API entry point for the internal support chatbot.
 * - Acts as a thin transport/controller layer that validates and normalizes
 *   request payload data before delegating all business logic to SupportChatService.
 *
 * Responsibilities:
 * - Decode JSON request payload.
 * - Extract and type-normalize supported parameters.
 * - Forward the request to the chat service.
 * - Return the service result as a JSON response.
 *
 * Design notes:
 * - This controller intentionally contains no chatbot logic.
 * - All orchestration, AI routing, KB/SOP matching, caching and usage tracking
 *   is handled by SupportChatService and downstream services.
 * - The API is designed to be stable for SPA or other frontend consumers.
 */
final class ChatController extends AbstractController
{
    /**
     * @param SupportChatService $chatService Central service orchestrating the support chat flow.
     */
    public function __construct(
        private readonly SupportChatService $chatService,
    ) {}

    /**
     * Handle a chat request from the frontend.
     *
     * Expected JSON payload (simplified):
     * {
     *   "sessionId": "string",                // client-side session identifier
     *   "message": "string",                  // user input / question
     *   "dbOnlySolutionId": 123|null,          // optional: force DB-only solution
     *   "provider": "gemini"|"openai"|...,     // optional: AI provider (default: gemini)
     *   "model": "string"|null,                // optional: provider-specific model
     *   "context": { ... }                     // optional: structured context data
     * }
     *
     * Notes:
     * - Unknown or missing fields are ignored.
     * - context must be an array if present; otherwise it is discarded.
     * - dbOnlySolutionId is only accepted if numeric; otherwise it is treated as null.
     *
     * @param Request $request Incoming HTTP request.
     *
     * @return JsonResponse JSON response containing the chatbot result and metadata.
     */
    #[Route('/api/chat', name: 'app_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?: [];

        // Session identifier used for conversation state / caching
        $sessionId = (string) ($payload['sessionId'] ?? '');

        // User message / question
        $message = (string) ($payload['message'] ?? '');

        // Optional: enforce DB-only solution lookup
        $dbOnly = $payload['dbOnlySolutionId'] ?? null;
        $dbOnlySolutionId = is_numeric($dbOnly) ? (int) $dbOnly : null;

        // AI provider and optional model override
        $provider = (string) ($payload['provider'] ?? 'gemini');
        $model = isset($payload['model']) && is_string($payload['model'])
            ? $payload['model']
            : null;

        // Optional structured context (must be an array)
        $context = [];
        if (isset($payload['context']) && is_array($payload['context'])) {
            $context = $payload['context'];
        }

        // Delegate processing to the support chat service
        $result = $this->chatService->ask(
            sessionId: $sessionId,
            message: $message,
            dbOnlySolutionId: $dbOnlySolutionId,
            provider: $provider,
            model: $model,
            context: $context,
        );

        // Echo back provider/model metadata for transparency/debugging
        $result['provider'] = $provider;
        if ($model !== null) {
            $result['model'] = $model;
        }

        return new JsonResponse($result);
    }
}
