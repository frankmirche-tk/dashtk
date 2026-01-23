<?php

declare(strict_types=1);

namespace App\Tracing;

use Doctrine\DBAL\Connection;

final class TraceRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function ensureTraceHeader(string $traceId, ?string $view): void
    {
        $this->db->executeStatement(
            'INSERT INTO ai_traces (trace_id, view, exported_at)
             VALUES (:trace_id, :view, NOW())
             ON DUPLICATE KEY UPDATE
                view = VALUES(view),
                exported_at = VALUES(exported_at)',
            [
                'trace_id' => $traceId,
                'view' => $view,
            ]
        );
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function insertSpan(
        string $traceId,
        string $spanId,
        ?string $parentSpanId,
        int $sequence,
        string $name,
        int $startedAtMs,
        int $endedAtMs,
        int $durationMs,
        array $meta = [],
    ): void {
        $metaJson = null;
        if ($meta !== []) {
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE);
            $metaJson = $encoded !== false ? $encoded : null;
        }

        // Optional: idempotent (wenn gleicher span_id nochmal kommt)
        $this->db->executeStatement(
            'INSERT INTO ai_trace_spans
                (trace_id, span_id, parent_span_id, sequence, name, started_at_ms, ended_at_ms, duration_ms, meta_json)
             VALUES
                (:trace_id, :span_id, :parent_span_id, :sequence, :name, :started_at_ms, :ended_at_ms, :duration_ms, :meta_json)
             ON DUPLICATE KEY UPDATE
                parent_span_id = VALUES(parent_span_id),
                sequence = VALUES(sequence),
                name = VALUES(name),
                started_at_ms = VALUES(started_at_ms),
                ended_at_ms = VALUES(ended_at_ms),
                duration_ms = VALUES(duration_ms),
                meta_json = VALUES(meta_json)',
            [
                'trace_id' => $traceId,
                'span_id' => $spanId,
                'parent_span_id' => $parentSpanId,
                'sequence' => $sequence,
                'name' => $name,
                'started_at_ms' => $startedAtMs,
                'ended_at_ms' => $endedAtMs,
                'duration_ms' => $durationMs,
                'meta_json' => $metaJson,
            ]
        );
    }
}
