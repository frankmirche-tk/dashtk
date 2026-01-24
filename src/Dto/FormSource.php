<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * DTO representing a single external form source configuration.
 *
 * A form source defines a curated external location (e.g. Google Drive folder)
 * from which form documents can be selected during FORM creation/editing.
 *
 * This object is immutable and configuration-driven.
 */
final readonly class FormSource
{
    /**
     * @param string $id        Unique technical identifier (e.g. "hr_forms")
     * @param string $label     Human-readable label shown in the UI
     * @param string $type      Source type (e.g. "google_drive")
     * @param string $folderUrl Public URL of the external folder
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $type,
        public string $folderUrl,
    ) {
    }

    /**
     * Convert the source to a normalized array representation.
     *
     * @return array{id:string,label:string,type:string,folderUrl:string}
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'label'     => $this->label,
            'type'      => $this->type,
            'folderUrl' => $this->folderUrl,
        ];
    }
}
