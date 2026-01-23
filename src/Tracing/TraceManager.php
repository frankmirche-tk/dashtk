<?php

declare(strict_types=1);

namespace App\Tracing;

final class TraceManager
{
    public function __construct(
        private readonly TraceRepository $repo,
    ) {}

    public function start(string $view): Trace
    {
        $traceId = self::uuidV4();
        $this->repo->ensureTraceHeader($traceId, $view);

        return new Trace(
            traceId: $traceId,
            view: $view,
            repo: $this->repo,
        );
    }

    /**
     * Finalize trace.
     * Spans are persisted in Trace::span(), header in start().
     * So this is currently just a semantic "finish" hook.
     */
    public function close(Trace $trace): void
    {
        $trace->finish();
        // nothing else to persist here
    }

    /**
     * Backwards compatible alias (if you used it earlier in controllers).
     */
    public function saveAndClose(Trace $trace): void
    {
        $this->close($trace);
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
