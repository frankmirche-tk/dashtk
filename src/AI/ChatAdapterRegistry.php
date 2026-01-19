<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;

final class ChatAdapterRegistry
{
    /** @var ProviderChatAdapterFactoryInterface[] */
    private array $factories;

    /**
     * @param iterable<ProviderChatAdapterFactoryInterface> $factories
     */
    public function __construct(iterable $factories)
    {
        $this->factories = is_array($factories) ? $factories : iterator_to_array($factories);
    }

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
