<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tracing\Export\TraceExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class TraceExportController extends AbstractController
{
    public function __construct(
        private readonly TraceExportService $exportService,
    ) {}

    #[Route('/api/trace/export', name: 'api_trace_export', methods: ['POST'])]
    public function export(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?: [];

        // ✅ Frontend schickt aktuell trace_id – manche Varianten traceId -> wir akzeptieren beides
        $traceId = (string) ($payload['trace_id'] ?? $payload['traceId'] ?? '');
        $view    = (string) ($payload['view'] ?? 'unknown');

        if (trim($traceId) === '') {
            return new JsonResponse(['ok' => false, 'error' => 'trace_id missing'], 400);
        }

        $result = $this->exportService->exportToJsonFile($traceId, $view);

        // ok=false -> 500 macht UX mies, besser 200 + ok=false
        return new JsonResponse($result, 200);
    }
}
