<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;

/**
 * ChatAdapterRegistry
 *
 * Purpose:
 * - Central registry/factory selector for provider-specific chat adapter factories.
 * - Resolves the correct ProviderChatAdapterFactoryInterface implementation based on a provider key
 *   (e.g. "gemini", "openai") and returns an AIChatAdapterInterface instance.
 *
 * Design:
 * - Uses an iterable of ProviderChatAdapterFactoryInterface instances (usually Symfony DI tagged services).
 * - Normalizes provider keys to lowercase and trims whitespace for consistent matching.
 * - Delegates construction of the actual adapter to the matching factory.
 *
 * Options:
 * - The registry forwards the $options array unchanged to the selected factory.
 * - Typical options used by factories include:
 *   - model: provider model override
 *   - context: provider-specific structured configuration (if the factory supports it)
 *
 * Error handling:
 * - Throws InvalidArgumentException if no factory supports the given provider key.
 *
 * Operational notes:
 * - Provider keys should be considered part of a public/internal API contract with the frontend
 *   (e.g. ChatController payload "provider").
 * - Keep the supports() checks strict and deterministic to avoid surprising provider selection.
 */
final class ChatAdapterRegistry
{
    /**
     * Provider adapter factories available to this registry.
     *
     * @var array<int, ProviderChatAdapterFactoryInterface>
     */
    private array $factories;

    /**
     * @param iterable<ProviderChatAdapterFactoryInterface> $factories Iterable factories (e.g. Symfony tagged iterator).
     */
    public function __construct(iterable $factories)
    {
        // Normalize to an array so we can iterate multiple times deterministically.
        $this->factories = is_array($factories) ? $factories : iterator_to_array($factories);
    }

    /**
     * Create a chat adapter for the given provider.
     *
     * Provider selection:
     * - The provider key is normalized (trim + lowercase).
     * - The first factory whose supports($provider) returns true is used.
     *
     * @param string               $provider Provider key (e.g. "gemini", "openai").
     * @param array<string, mixed> $options  Provider-specific options forwarded to the factory.
     *
     * @return AIChatAdapterInterface Provider-specific adapter instance.
     *
     * @throws \InvalidArgumentException If no factory supports the given provider key.
     */
    public function create(string $provider, array $options = []): AIChatAdapterInterface
    {
        $provider = strtolower(trim($provider));

        foreach ($this->factories as $factory) {
            if ($factory->supports($provider)) {
                return $factory->create($options);
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported AI provider "%s"', $provider));
    }
}
