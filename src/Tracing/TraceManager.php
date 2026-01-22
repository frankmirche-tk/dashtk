<?php

namespace App\Tracing;

final class TraceManager
{
    public function __construct(
        private readonly TraceRepository $repository
    ) {}

    public function start(string $operation): Trace
    {
        return new Trace($this->repository, $operation);
    }
}
