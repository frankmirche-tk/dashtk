<?php

namespace App\Tracing;

use Ramsey\Uuid\Uuid;

final class Trace
{
    private string $traceId;
    private int $sequence = 0;
    private float $startedAt;

    public function __construct(
        private readonly TraceRepository $repository,
        private readonly string $operation
    ) {
        $this->traceId = Uuid::uuid4()->toString();
        $this->startedAt = microtime(true);

        $this->repository->startTrace($this->traceId, $this->operation);
    }

    public function span(string $name, callable $fn, array $meta = []): mixed
    {
        $start = microtime(true);
        $result = $fn();
        $duration = (int) ((microtime(true) - $start) * 1000);

        $this->repository->addSpan(
            traceId: $this->traceId,
            name: $name,
            durationMs: $duration,
            sequence: ++$this->sequence,
            meta: $meta
        );

        return $result;
    }

    public function finish(): void
    {
        $total = (int) ((microtime(true) - $this->startedAt) * 1000);
        $this->repository->finishTrace($this->traceId, $total);
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }
}
