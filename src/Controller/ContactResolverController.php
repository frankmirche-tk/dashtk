<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ContactResolver;
use App\Tracing\Trace;
use App\Tracing\TraceManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ContactResolverController extends AbstractController
{
    public function __construct(
        private readonly ContactResolver $resolver,
        private readonly TraceManager $traceManager,
        private readonly string $appEnv, // bind $appEnv: '%kernel.environment%'
        private readonly LoggerInterface $contactLookupLogger, // services.yaml passt jetzt ✅
    ) {}

    #[Route('/api/contact/resolve', name: 'api_contact_resolve', methods: ['POST'])]
    public function resolve(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?: [];
        $query = (string) ($payload['query'] ?? '');

        $trace = null;

        // Trace + Logging nur in DEV
        if ($this->appEnv === 'dev') {
            $trace = $this->traceManager->start('ContactResolverController::resolve');

            // Root-Span
            $trace->span('http.api.contact_resolve', fn () => null, [
                'method' => $request->getMethod(),
                'path'   => $request->getPathInfo(),
            ]);

            $uiSpan = $request->headers->get('X-UI-Span');
            $uiAt   = $request->headers->get('X-UI-At');
            if ($uiSpan) {
                $trace->span((string) $uiSpan, fn () => null, [
                    'ui_at' => $uiAt ? (int) $uiAt : null,
                ]);
            }
        }

        // Resolver ausführen (mit optionalem Trace)
        $result = $this->resolver->resolve($query, 5, $trace);

        if ($this->appEnv === 'dev') {
            $this->contactLookupLogger->info('contact_resolve', [
                'query_len' => mb_strlen($query),
                'type'      => $result['type'] ?? null,
                'matches'   => is_array($result['matches'] ?? null) ? count($result['matches']) : null,
                'trace_id'  => $trace?->getTraceId(),
            ]);
        }

        if ($trace) {
            $this->traceManager->close($trace);
            $result['trace_id'] = $trace->getTraceId();
        }

        return $this->json($result);
    }
}
