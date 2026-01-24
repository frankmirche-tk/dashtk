<?php

declare(strict_types=1);

namespace App\Service;

final class FormSourceProvider
{
    private array $sources;

    public function __construct(string $projectDir)
    {
        $file = $projectDir . '/var/data/forms_sources.json';
        $this->sources = is_file($file)
            ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
            : [];
    }

    public function getAllForms(): array
    {
        $forms = [];

        foreach ($this->sources as $source) {
            foreach ($source['forms'] ?? [] as $form) {
                $forms[] = $form;
            }
        }

        return $forms;
    }
}
