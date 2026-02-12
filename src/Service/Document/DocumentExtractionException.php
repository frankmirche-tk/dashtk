<?php

declare(strict_types=1);

namespace App\Service\Document;

final class DocumentExtractionException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'extract_failed',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
