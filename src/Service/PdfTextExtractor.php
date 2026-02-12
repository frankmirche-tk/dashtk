<?php
// src/Service/PdfTextExtractor.php

namespace App\Service;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class PdfTextExtractor
{
    /**
     * Extrahiert Text aus PDF via pdftotext (stdout).
     */
    public function extractToText(string $pdfPath): string
    {
        // -layout = besser für Newsletter/Spalten, "-" = stdout
        $process = new Process(['pdftotext', '-layout', $pdfPath, '-']);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return trim($process->getOutput());
    }
}
