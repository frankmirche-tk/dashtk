<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\SupportSolution;
use Symfony\Component\Validator\Constraints\GroupSequence;
use Symfony\Component\Validator\GroupSequenceProviderInterface;

/**
 * Dynamically defines validation groups based on solution type.
 */
final class SupportSolutionGroupSequenceProvider implements GroupSequenceProviderInterface
{
    /**
     * @param SupportSolution $object
     *
     * @return GroupSequence|array
     */
    public function getGroupSequence(): GroupSequence|array
    {
        if ($this->type === 'FORM') {
            return new GroupSequence(['Default', 'form']);
        }

        return new GroupSequence(['Default', 'sop']);
    }
}
