<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FormSourceProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only API endpoint exposing available external form sources.
 *
 * Intended usage:
 * - FORM creation/editing UI
 * - External media selection (e.g. Google Drive folders)
 *
 * No authentication or filtering logic is applied here yet.
 */
final class FormSourceController extends AbstractController
{
    public function __construct(
        private readonly FormSourceProvider $formSourceProvider,
    ) {
    }

    #[Route('/api/support/form-sources', name: 'api_support_form_sources', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $sources = array_map(
            static fn ($source) => $source->toArray(),
            $this->formSourceProvider->getSources()
        );

        return $this->json([
            'sources' => $sources,
        ]);
    }
}
