<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ExternalFormSelection;
use App\Entity\SupportSolution;

/**
 * Applies an external form document reference to a SupportSolution.
 *
 * This service ensures that FORM solutions are consistently
 * configured when using external media sources.
 */
final class ExternalFormMediaApplier
{
    public function apply(
        SupportSolution $solution,
        ExternalFormSelection $selection
    ): void {
        $solution
            ->setType('FORM')
            ->useExternalMedia(
                $selection->provider,
                $selection->url,
                $selection->externalId
            );
    }
}
