<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use OpenAI\Client;

/**
 * OpenAiChatAdapterFactory
 *
 * Purpose:
 * - Factory for creating a Modelflow-compatible chat adapter for the OpenAI provider.
 * - Encapsulates provider-specific dependencies (OpenAI SDK client) and default configuration (model).
 *
 * How it is used:
 * - Typically called by a registry (e.g. ChatAdapterRegistry) which selects a provider factory
 *   based on a provider key string (e.g. "openai").
 * - Returns an AIChatAdapterInterface implementation (OpenAiChatAdapter) which can handle
 *   AIChatRequest objects produced by AiChatGateway.
 *
 * Provider key:
 * - This factory supports the provider key: "openai"
 * - Provider keys are expected to be normalized (lowercase) before calling supports().
 *
 * Model selection:
 * - The model can be overridden via $options['model'].
 * - If no model is provided, the configured $defaultModel is used.
 *
 * Expected options:
 * - model?: string|null  Provider model identifier/name (OpenAI-specific).
 *
 * Notes:
 * - Advanced OpenAI request options (temperature, tools, response format, etc.) are not configured here.
 *   If required, extend OpenAiChatAdapter to accept additional options and forward them to the SDK call.
 */
final readonly class OpenAiChatAdapterFactory implements ProviderChatAdapterFactoryInterface
{
    /**
     * @param Client $client       Configured OpenAI SDK client instance.
     * @param string $defaultModel Default OpenAI model used when no override is provided.
     */
    public function __construct(
        private Client $client,
        private string $defaultModel,
    ) {}

    /**
     * Whether this factory supports the given provider key.
     *
     * @param string $provider Provider key (expected: "openai").
     *
     * @return bool True if the factory supports the provider.
     */
    public function supports(string $provider): bool
    {
        return $provider === 'openai';
    }

    /**
     * Create an OpenAI chat adapter instance.
     *
     * Options:
     * - model: string|null  Overrides the configured default model.
     *
     * @param array<string, mixed> $options Provider-specific options.
     *
     * @return AIChatAdapterInterface Modelflow chat adapter backed by OpenAI.
     */
    public function create(array $options = []): AIChatAdapterInterface
    {
        $model = $options['model'] ?? $this->defaultModel;

        return new OpenAiChatAdapter(
            $this->client,
            (string) $model
        );
    }
}
