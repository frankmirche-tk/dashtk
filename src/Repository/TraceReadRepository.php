<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class TraceReadRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{0: array<string,mixed>|null, 1: array<int,array<string,mixed>>}
     */
    public function fetch(string $traceId): array
    {
        $trace = $this->db->fetchAssociative(
            'SELECT trace_id, view, exported_at
             FROM ai_traces
             WHERE trace_id = ?',
            [$traceId]
        );

        $spans = $this->db->fetchAllAssociative(
            'SELECT
                trace_id,
                span_id,
                parent_span_id,
                sequence,
                name,
                started_at_ms,
                ended_at_ms,
                duration_ms,
                meta_json
             FROM ai_trace_spans
             WHERE trace_id = ?
             ORDER BY sequence ASC',
            [$traceId]
        );

        return [$trace ?: null, $spans];
    }
}
