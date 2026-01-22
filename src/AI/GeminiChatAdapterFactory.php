<?php

declare(strict_types=1);

namespace App\AI;

use Gemini\Contracts\ClientContract;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\GoogleGeminiAdapter\Chat\GoogleGeminiChatAdapter;

final readonly class GeminiChatAdapterFactory implements ProviderChatAdapterFactoryInterface
{
    public function __construct(
        private ClientContract $client,
        private string $defaultModel,
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

        return new GeminiChatAdapter($vendor);
    }
}
