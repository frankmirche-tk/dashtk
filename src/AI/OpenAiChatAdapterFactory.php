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
            responseFormat: is_array($responseFormat) ? $responseFormat : null,
            temperature: is_numeric($temperature) ? (float) $temperature : null,
        );
    }
}
