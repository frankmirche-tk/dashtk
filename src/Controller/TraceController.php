<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TraceReadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class TraceController extends AbstractController
{
    #[Route('/api/ai-traces/{traceId}', name: 'app_trace_show', methods: ['GET'])]
    public function show(string $traceId, TraceReadRepository $repository): JsonResponse
    {
        [$trace, $spans] = $repository->fetch($traceId);

        if (!$trace) {
            return $this->json(['error' => 'Trace not found'], 404);
        }

        $out = array_map(static function (array $s): array {
            return [
                'sequence' => (int)($s['sequence'] ?? 0),
                'name' => (string)($s['name'] ?? ''),
                'span_id' => (string)($s['span_id'] ?? ''),
                'parent_span_id' => $s['parent_span_id'] !== null ? (string)$s['parent_span_id'] : null,
                'started_at_ms' => (int)($s['started_at_ms'] ?? 0),
                'ended_at_ms' => (int)($s['ended_at_ms'] ?? 0),
                'duration_ms' => (int)($s['duration_ms'] ?? 0),
                'meta' => $s['meta_json'] ? (json_decode((string)$s['meta_json'], true) ?: []) : [],
            ];
        }, $spans);

        return $this->json([
            'trace_id' => $traceId,
            'view' => (string)($trace['view'] ?? ''),
            'exported_at' => (string)($trace['exported_at'] ?? ''),
            'spans' => $out,
        ]);
    }
}
