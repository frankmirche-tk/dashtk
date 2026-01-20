<?php

declare(strict_types=1);

namespace App\AI;

use Gemini\Contracts\ClientContract;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use ModelflowAi\GoogleGeminiAdapter\Chat\GoogleGeminiChatAdapter;

/**
 * GeminiChatAdapterFactory
 *
 * Purpose:
 * - Factory for creating a Modelflow-compatible chat adapter for Google's Gemini provider.
 * - Encapsulates provider-specific dependencies (Gemini client) and default configuration (model).
 *
 * How it is used:
 * - Typically called by a registry (e.g. ChatAdapterRegistry) that selects a provider factory
 *   based on a provider key (string).
 * - This factory returns an AIChatAdapterInterface implementation that can handle AIChatRequest
 *   objects produced by AiChatGateway.
 *
 * Provider key:
 * - This factory supports the provider key: "gemini"
 * - Provider keys are expected to be normalized (lowercase) before calling supports().
 *
 * Model selection:
 * - The model can be overridden via $options['model'].
 * - If no model is provided, the configured $defaultModel is used.
 *
 * Expected options:
 * - model?: string|null  Provider model identifier/name (Gemini-specific).
 *
 * Notes:
 * - The factory intentionally does not handle advanced provider configuration here
 *   (e.g. safety settings, temperature, tools). Such configuration should be passed either:
 *   - via the underlying client configuration, or
 *   - via additional options in a future extension of this factory/adapter setup.
 */
final readonly class GeminiChatAdapterFactory implements ProviderChatAdapterFactoryInterface
{
    /**
     * @param ClientContract $client       Configured Gemini client instance used by the adapter.
     * @param string         $defaultModel Default Gemini model used when no override is provided.
     */
    public function __construct(
        private ClientContract $client,
        private string $defaultModel,
    ) {}

    /**
     * Whether this factory supports the given provider key.
     *
     * @param string $provider Provider key (expected: "gemini").
     *
     * @return bool True if the factory supports the provider.
     */
    public function supports(string $provider): bool
    {
        return $provider === 'gemini';
    }

    /**
     * Create a Gemini chat adapter instance.
     *
     * Options:
     * - model: string|null  Overrides the configured default model.
     *
     * @param array<string, mixed> $options Provider-specific options.
     *
     * @return AIChatAdapterInterface Modelflow chat adapter backed by Gemini.
     */
    public function create(array $options = []): AIChatAdapterInterface
    {
        $model = $options['model'] ?? $this->defaultModel;

        return new GoogleGeminiChatAdapter(
            $this->client,
            (string) $model
        );
    }
}
