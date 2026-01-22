<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use OpenAI\Client;

final readonly class OpenAiChatAdapterFactory implements ProviderChatAdapterFactoryInterface
{
    public function __construct(
        private Client $client,
        private string $defaultModel,
    ) {}

    public function supports(string $provider): bool
    {
        return $provider === 'openai';
    }

    public function create(array $options = []): AIChatAdapterInterface
    {
        $model = (string) ($options['model'] ?? $this->defaultModel);

        return new OpenAiChatAdapter(
            $this->client,
            $model
        );
    }
}
