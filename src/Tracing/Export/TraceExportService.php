<?php

declare(strict_types=1);

namespace App\Tracing\Export;

use App\Repository\TraceReadRepository;

final readonly class TraceExportService
{
    public function __construct(
        private TraceReadRepository $readRepository,
        private string $projectDir, // kernel.project_dir
    ) {}

    /**
     * @return array{ok:bool, file?:string, path?:string, error?:string, trace_id?:string}
     */
    public function exportToJsonFile(string $traceId, string $view): array
    {
        $traceId = trim($traceId);
        $view = trim($view) !== '' ? trim($view) : 'unknown';

        if ($traceId === '') {
            return ['ok' => false, 'error' => 'traceId missing'];
        }

        // ✅ DB read über TraceReadRepository
        [$trace, $spans] = $this->readRepository->fetch($traceId);

        if (!$trace) {
            return ['ok' => false, 'error' => 'trace not found', 'trace_id' => $traceId];
        }

        // ✅ sicher nach sequence sortieren
        usort(
            $spans,
            static fn(array $a, array $b) => ((int)($a['sequence'] ?? 0)) <=> ((int)($b['sequence'] ?? 0))
        );

        $payload = [
            'view' => $view,
            'trace_id' => $traceId,
            'total_ms' => (int)($trace['total_ms'] ?? 0),
            'exported_at' => (new \DateTimeImmutable())->format(DATE_ATOM),

            // raw spans
            'spans' => array_map(static function (array $s): array {
                return [
                    'sequence' => (int)($s['sequence'] ?? 0),
                    'name' => (string)($s['name'] ?? ''),
                    'duration_ms' => (int)($s['duration_ms'] ?? 0),
                    'meta' => json_decode((string)($s['meta_json'] ?? '[]'), true) ?: [],
                ];
            }, $spans),

            // tree (lineare Kette) – bleibt für Export/Debug
            'tree' => $this->toLinearTree($spans),
        ];

        $dir = $this->projectDir . '/var/docs/trace';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $safeView = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $view) ?: 'unknown';

        $file = sprintf(
            '%s/%s_Trace_Tree_%s.json',
            $dir,
            $safeView,
            (new \DateTimeImmutable())->format('Y-m-d_H-i-s')
        );

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'json_encode failed', 'trace_id' => $traceId];
        }

        $bytes = @file_put_contents($file, $json);
        if ($bytes === false) {
            return ['ok' => false, 'error' => 'file_put_contents failed: ' . $file, 'trace_id' => $traceId];
        }

        return [
            'ok' => true,
            'trace_id' => $traceId,
            'file' => basename($file),
            'path' => $file,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $spans
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array{from:string,to:string}>}
     */
    private function toLinearTree(array $spans): array
    {
        $nodes = [];
        $edges = [];

        $prevName = null;

        foreach ($spans as $span) {
            $name = (string)($span['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $nodes[] = [
                'id' => $name,
                'duration_ms' => (int)($span['duration_ms'] ?? 0),
                'meta' => json_decode((string)($span['meta_json'] ?? '[]'), true) ?: [],
            ];

            if ($prevName !== null) {
                $edges[] = ['from' => $prevName, 'to' => $name];
            }

            $prevName = $name;
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }
}
