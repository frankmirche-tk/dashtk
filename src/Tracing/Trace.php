<?php

declare(strict_types=1);

namespace App\Tracing;

final class Trace
{
    private int $sequence = 0;

    /** @var list<string> */
    private array $stack = [];

    public function __construct(
        private readonly string $traceId,
        private readonly string $view,
        private readonly TraceRepository $repo,
    ) {}

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getView(): string
    {
        return $this->view;
    }

    /**
     * Creates a span (nested) and persists it after execution.
     *
     * @template T
     * @param callable():T $fn
     * @param array<string,mixed> $meta
     * @return T
     */
    public function span(string $name, callable $fn, array $meta = [])
    {
        $this->sequence++;
        $sequence = $this->sequence;

        $spanId = self::uuidV4();
        $parentSpanId = $this->stack ? $this->stack[count($this->stack) - 1] : null;

        $startedAtMs = (int) floor(microtime(true) * 1000);

        // push current span
        $this->stack[] = $spanId;

        try {
            /** @var T $result */
            $result = $fn();
        } finally {
            // pop even if exception
            array_pop($this->stack);

            $endedAtMs = (int) floor(microtime(true) * 1000);
            $durationMs = max(0, $endedAtMs - $startedAtMs);

            $this->repo->insertSpan(
                traceId: $this->traceId,
                spanId: $spanId,
                parentSpanId: $parentSpanId,
                sequence: $sequence,
                name: $name,
                startedAtMs: $startedAtMs,
                endedAtMs: $endedAtMs,
                durationMs: $durationMs,
                meta: $meta,
            );
        }

        return $result;
    }

    /**
     * Optional finalize hook.
     * Spans are persisted on the fly, so this is currently a no-op.
     */
    public function finish(): void
    {
        // no-op; spans are persisted on the fly
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
