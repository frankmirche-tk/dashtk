<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;

final class FormResolver
{
    public function __construct(
        private readonly SupportSolutionRepository $solutionRepository,
        private readonly FormSourceProvider $formSourceProvider,
    ) {}

    /**
     * Try to resolve form-related requests from user input.
     *
     * @return array{
     *   matches: array<int, mixed>,
     *   confidence: int
     * }
     */
    public function resolve(string $input): array
    {
        $matches = [];

        // 1️⃣ DB-Forms (SupportSolution)
        $dbForms = $this->solutionRepository->findActiveForms();
        foreach ($dbForms as $form) {
            if ($this->matchesForm($form, $input)) {
                $matches[] = $this->mapDbForm($form);
            }
        }

        // 2️⃣ JSON-Forms (Google Drive etc.)
        foreach ($this->formSourceProvider->getAllForms() as $externalForm) {
            if ($this->matchesExternalForm($externalForm, $input)) {
                $matches[] = $externalForm;
            }
        }

        return [
            'matches' => $matches,
            'confidence' => $this->calculateConfidence($matches),
        ];
    }

    private function matchesForm(SupportSolution $form, string $input): bool
    {
        $input = mb_strtolower($input);

        if (str_contains(mb_strtolower($form->getTitle()), $input)) {
            return true;
        }

        foreach ($form->getKeywords() as $keyword) {
            if (str_contains($input, mb_strtolower($keyword->getKeyword()))) {
                return true;
            }
        }

        return false;
    }

    private function matchesExternalForm(array $form, string $input): bool
    {
        return str_contains(mb_strtolower($form['title']), mb_strtolower($input));
    }

    private function mapDbForm(SupportSolution $form): array
    {
        return [
            'type' => 'db',
            'title' => $form->getTitle(),
            'url' => $form->getExternalMediaUrl(),
            'provider' => $form->getExternalMediaProvider(),
        ];
    }

    private function calculateConfidence(array $matches): int
    {
        return count($matches) > 0 ? 90 : 0;
    }
}
