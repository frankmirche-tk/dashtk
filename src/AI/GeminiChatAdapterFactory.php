<?php

declare(strict_types=1);

namespace App\AI;

use Gemini\Contracts\ClientContract;
use ModelflowAi\Chat\Adapter\AIChatAdapterFactoryInterface;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\GoogleGeminiAdapter\Chat\GoogleGeminiChatAdapter;

final readonly class GeminiChatAdapterFactory implements AIChatAdapterFactoryInterface
{
    public function __construct(
        private ClientContract $client,
        private string $defaultModel,
    ) {}

    public function createChatAdapter(array $options): AIChatAdapterInterface
    {
        $model = $options['model'] ?? $this->defaultModel;
        return new GoogleGeminiChatAdapter($this->client, $model);
    }
}
