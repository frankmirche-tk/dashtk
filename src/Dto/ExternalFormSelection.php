<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * DTO representing a selected external form document.
 *
 * Used during FORM creation/editing when selecting
 * a document from an external provider (e.g. Google Drive).
 */
final readonly class ExternalFormSelection
{
    /**
     * @param string      $provider   External provider identifier
     * @param string      $url        Public file URL
     * @param string|null $externalId Optional provider-specific file ID
     */
    public function __construct(
        public string $provider,
        public string $url,
        public ?string $externalId = null,
    ) {
    }
}
