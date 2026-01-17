<?php

namespace App\Controller;

use App\Service\ContactResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

final class ContactResolverController extends AbstractController
{
    public function __construct(
        private readonly ContactResolver $resolver,
        private readonly LoggerInterface $contactLookupLogger,
    ) {}

    #[Route('/api/contact/resolve', name: 'api_contact_resolve', methods: ['POST'])]
    public function resolve(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?: [];
        $query = (string)($payload['query'] ?? '');

        $this->contactLookupLogger->info('contact_resolve_request', [
            'query' => $query,
        ]);

        $result = $this->resolver->resolve($query, 10);

        $this->contactLookupLogger->info('contact_resolve_result', [
            'query' => $query,
            'type' => $result['type'] ?? null,
            'matchCount' => isset($result['matches']) && is_array($result['matches']) ? count($result['matches']) : null,
            'matchIds' => array_map(static fn($m) => $m['id'] ?? null, $result['matches'] ?? []),
        ]);

        return new JsonResponse($result);
    }
}
