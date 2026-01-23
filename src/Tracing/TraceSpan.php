<?php

declare(strict_types=1);

namespace App\Tracing;

final class TraceSpan
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly int $sequence,
        public readonly string $name,
        public readonly int $startedAtMs,
        public int $endedAtMs,
        public int $durationMs,
        public array $meta = [],
    ) {}
}
