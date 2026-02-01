<?php

declare(strict_types=1);

namespace App\AI;

use App\Tracing\TraceContext;
use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;
use Psr\Log\LoggerInterface;

final class ChatAdapterRegistry
{
    /**
     * @var array<int, ProviderChatAdapterFactoryInterface>
     */
    private array $factories;

    public function __construct(
        iterable $factories,
        private ?LoggerInterface $logger = null,
    ) {
        $this->factories = is_array($factories) ? $factories : iterator_to_array($factories);
    }

    public function create(string $provider, array $options = []): AIChatAdapterInterface
    {
        return TraceContext::span('registry.adapter.resolve', function () use ($provider, $options) {
            $provider = strtolower(trim($provider));
            $model = is_string($options['model'] ?? null) ? (string) $options['model'] : null;

            foreach ($this->factories as $factory) {
                $supports = TraceContext::span('registry.factory.supports', function () use ($factory, $provider) {
                    return $factory->supports($provider);
                }, [
                    'provider' => $provider,
                    'factory_class' => is_object($factory) ? $factory::class : gettype($factory),
                ]);

                if ($supports) {
                    $adapter = TraceContext::span('registry.factory.create_adapter', function () use ($factory, $options) {
                        return $factory->create($options);
                    }, [
                        'factory_class' => is_object($factory) ? $factory::class : gettype($factory),
                    ]);

                    TraceContext::span('registry.adapter.created', static function () {
                        return null;
                    }, [
                        'provider' => $provider,
                        'model' => $model,
                        'adapter_class' => is_object($adapter) ? $adapter::class : gettype($adapter),
                    ]);

                    if ($this->logger) {
                        $this->logger->info('ai.registry.adapter_created', [
                            'provider' => $provider,
                            'model' => $model,
                            'factory_class' => is_object($factory) ? $factory::class : gettype($factory),
                            'adapter_class' => is_object($adapter) ? $adapter::class : gettype($adapter),
                            'factories_total' => count($this->factories),
                        ]);
                    }

                    return $adapter;
                }
            }

            if ($this->logger) {
                $this->logger->error('ai.registry.unsupported_provider', [
                    'provider' => $provider,
                    'model' => $model,
                    'factories_total' => count($this->factories),
                    'factory_classes' => array_map(
                        static fn ($f) => is_object($f) ? $f::class : gettype($f),
                        $this->factories
                    ),
                ]);
            }

            throw new \InvalidArgumentException(sprintf('Unsupported AI provider "%s"', $provider));
        }, [
            'provider' => strtolower(trim($provider)),
            'model' => is_string($options['model'] ?? null) ? (string) $options['model'] : null,
        ]);
    }
}
