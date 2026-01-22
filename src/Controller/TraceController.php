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

        // ✅ Spans sicher sortieren
        usort(
            $spans,
            static fn(array $a, array $b) => ((int)($a['sequence'] ?? 0)) <=> ((int)($b['sequence'] ?? 0))
        );

        // nodes/edges als "lineare Kette" – fürs Frontend reicht das völlig
        $nodes = [];
        $edges = [];

        $prev = null;
        foreach ($spans as $span) {
            $name = (string)($span['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $nodes[] = [
                'id' => $name,
                'label' => $name,
                'duration_ms' => (int)($span['duration_ms'] ?? 0),
                'sequence' => (int)($span['sequence'] ?? 0),
                'meta' => json_decode((string)($span['meta_json'] ?? '[]'), true) ?: [],
            ];

            if ($prev !== null) {
                $edges[] = ['from' => $prev, 'to' => $name];
            }
            $prev = $name;
        }

        return $this->json([
            'trace_id' => $traceId,
            'total_ms' => (int)($trace['total_ms'] ?? 0),
            'nodes' => $nodes,
            'edges' => $edges,
            // optional: spans raw (praktisch für Debug / später)
            'spans' => array_map(static function (array $s): array {
                return [
                    'sequence' => (int)($s['sequence'] ?? 0),
                    'name' => (string)($s['name'] ?? ''),
                    'duration_ms' => (int)($s['duration_ms'] ?? 0),
                    'meta' => json_decode((string)($s['meta_json'] ?? '[]'), true) ?: [],
                ];
            }, $spans),
        ]);
    }
}
