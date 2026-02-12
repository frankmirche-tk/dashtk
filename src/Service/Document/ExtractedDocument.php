<?php

declare(strict_types=1);

namespace App\Service\Document;

final class ExtractedDocument
{
    /**
     * @param array<int, string> $warnings
     */
    public function __construct(
        public readonly string $text,
        public readonly array $warnings = [],
        public readonly bool $needsOcr = false,
        public readonly float $confidence = 0.9,
        public readonly string $method = 'unknown',
        public readonly ?string $mimeType = null,
        public readonly ?string $extension = null,
        public readonly ?string $filename = null,
    ) {}
}
