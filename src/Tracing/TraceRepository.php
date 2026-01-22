<?php

declare(strict_types=1);

namespace App\Tracing;

use Doctrine\DBAL\Connection;

final class TraceRepository
{
    public function __construct(
        private readonly Connection $db
    ) {}

    public function startTrace(string $traceId, string $operation): void
    {
        $this->db->insert('ai_trace', [
            'trace_id'   => $traceId,
            'operation'  => $operation,
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function addSpan(
        string $traceId,
        string $name,
        int $durationMs,
        int $sequence,
        array $meta = []
    ): void {
        $this->db->insert('ai_trace_span', [
            'trace_id'     => $traceId,
            'name'         => $name,
            'started_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'duration_ms'  => $durationMs,
            'sequence'     => $sequence,
            'meta_json'    => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function finishTrace(string $traceId, int $totalMs): void
    {
        $this->db->update('ai_trace', [
            'total_ms'   => $totalMs,
            'finished_at'=> (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], [
            'trace_id' => $traceId,
        ]);
    }
}
