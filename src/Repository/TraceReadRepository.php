<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class TraceReadRepository
{
    public function __construct(
        private readonly Connection $db
    ) {}

    public function fetch(string $traceId): array
    {
        $trace = $this->db->fetchAssociative(
            'SELECT * FROM ai_trace WHERE trace_id = ?',
            [$traceId]
        );

        $spans = $this->db->fetchAllAssociative(
            'SELECT * FROM ai_trace_span WHERE trace_id = ? ORDER BY sequence ASC',
            [$traceId]
        );

        return [$trace, $spans];
    }
}
