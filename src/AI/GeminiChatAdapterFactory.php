<?php

declare(strict_types=1);

namespace App\AI;

use Gemini\Contracts\ClientContract;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\GoogleGeminiAdapter\Chat\GoogleGeminiChatAdapter;
use Psr\Log\LoggerInterface;

final readonly class GeminiChatAdapterFactory implements ProviderChatAdapterFactoryInterface
{
    public function __construct(
        private ClientContract $client,
        private string $defaultModel,
        private LoggerInterface $logger,
        /**
         * Empfehlung: in PROD false, in DEV true
         */
        private bool $includeVendorDetails = false,
        /**
         * Empfehlung: in PROD true, in DEV/TEST optional false
         */
        private bool $enableFallback = true,
        /**
         * Optional: harte Obergrenze für Details/Snippets im Log/DEV-Fallback.
         */
        private int $maxDetailLen = 260,
    ) {}

    public function supports(string $provider): bool
    {
        return $provider === 'gemini';
    }

    public function create(array $options = []): AIChatAdapterInterface
    {
        $model = $options['model'] ?? $this->defaultModel;

        $vendor = new GoogleGeminiChatAdapter(
            $this->client,
            (string) $model
        );

        return new GeminiChatAdapter(
            $vendor,
            $this->logger,
            $this->includeVendorDetails,
            $this->enableFallback,
            $this->maxDetailLen
        );
    }
}
