<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Adapter\AIChatAdapterInterface;

interface ProviderChatAdapterFactoryInterface
{
    public function supports(string $provider): bool;

    /**
     * @param array{
     *   model?: string|null,
     *   context?: array<mixed>
     * } $options
     */
    public function create(array $options = []): AIChatAdapterInterface;
}
