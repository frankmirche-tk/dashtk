<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\SupportSolution;

/**
 * Custom processor for SupportSolution.
 *
 * Ensures correct domain behavior for SOP vs FORM
 * before delegating persistence to Doctrine.
 */
final class SupportSolutionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $doctrinePersistProcessor,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (!$data instanceof SupportSolution) {
            return $this->doctrinePersistProcessor->process($data, $operation, $uriVariables, $context);
        }

        // ---- Domain logic ----
        if ($data->getType() === 'FORM') {
            // FORM must not have steps
            foreach ($data->getSteps() as $step) {
                $data->removeStep($step);
            }
        }
        // ----------------------

        return $this->doctrinePersistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
