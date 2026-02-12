<?php

declare(strict_types=1);

namespace App\Service\Document;

interface ExtractorInterface
{
    /**
     * Extrahiert Text aus einem lokalen Dateipfad.
     *
     * @throws DocumentExtractionException
     */
    public function extract(string $path, ?string $filename = null, ?string $mimeType = null): ExtractedDocument;

    /**
     * Ob der Extractor für Extension/Mime passt.
     */
    public function supports(?string $extension, ?string $mimeType): bool;
}
