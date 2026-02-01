<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use OpenAI\Client;
use Psr\Log\LoggerInterface;

final readonly class OpenAiChatAdapterFactory implements ProviderChatAdapterFactoryInterface
{
    public function __construct(
        private Client $client,
        private string $defaultModel,
        private LoggerInterface $logger,
        /**
         * Empfehlung: in PROD false, in DEV true
         */
        private bool $includeVendorDetails = false,
        /**
         * Empfehlung: in PROD true
         */
        private bool $enableFallback = true,
        /**
         * Optional: harte Obergrenze für Details/Snippets im Log/DEV-Fallback.
         */
        private int $maxDetailLen = 260,
    ) {}

    public function supports(string $provider): bool
    {
        return strtolower(trim($provider)) === 'openai';
    }

    public function create(array $options = []): AIChatAdapterInterface
    {
        $model = (string) ($options['model'] ?? $this->defaultModel);

        $context = is_array($options['context'] ?? null) ? $options['context'] : [];

        $responseFormat = $context['response_format'] ?? null; // z.B. ['type' => 'json_object']
        $temperature    = $context['temperature'] ?? null;     // z.B. 0.2

        return new OpenAiChatAdapter(
            client: $this->client,
            model: $model,
            logger: $this->logger,
            responseFormat: is_array($responseFormat) ? $responseFormat : null,
            temperature: is_numeric($temperature) ? (float) $temperature : null,
            includeVendorDetails: $this->includeVendorDetails,
            enableFallback: $this->enableFallback,
            maxDetailLen: $this->maxDetailLen,
        );
    }
}
