<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;

/**
 * ProviderChatAdapterFactoryInterface
 *
 * Purpose:
 * - Contract for provider-specific factories that create Modelflow-compatible chat adapters.
 * - Used by ChatAdapterRegistry to select a factory based on a provider key (string) and
 *   to create an AIChatAdapterInterface instance for that provider.
 *
 * Design goals:
 * - Keep provider selection deterministic via supports().
 * - Keep adapter construction flexible via an $options array (model overrides, provider context, etc.).
 * - Allow multiple providers (Gemini/OpenAI/...) to be plugged in via Symfony DI without changing
 *   the registry or gateway.
 *
 * Provider keys:
 * - Provider keys are expected to be normalized by the caller/registry (typically lowercase).
 * - Implementations should treat supports() as a strict match against their provider identifier.
 *
 * Options:
 * - The options array is intentionally small and extendable.
 * - Implementations may ignore unknown options, but should document what they support.
 *
 * Typical options:
 * - model: string|null
 *   - Optional model override (provider-specific identifier).
 * - context: array<mixed>
 *   - Optional provider-specific structured context/config that can be forwarded further down
 *     (e.g. safety settings, extra headers, feature flags). If not supported by a provider, it can be ignored.
 */
interface ProviderChatAdapterFactoryInterface
{
    /**
     * Check whether the factory supports the given provider key.
     *
     * @param string $provider Provider key (e.g. "gemini", "openai").
     *
     * @return bool True if this factory can create adapters for the provider.
     */
    public function supports(string $provider): bool;

    /**
     * Create a provider-specific AI chat adapter.
     *
     * Implementations should:
     * - apply default configuration (e.g. default model) if no override is provided
     * - return a fully configured adapter instance that can handle Modelflow AIChatRequest objects
     *
     * @param array{
     *   model?: string|null,
     *   context?: array<mixed>
     * } $options Provider-specific options (optional).
     *
     * @return AIChatAdapterInterface Provider-specific adapter instance.
     */
    public function create(array $options = []): AIChatAdapterInterface;
}
