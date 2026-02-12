<?php

declare(strict_types=1);

namespace App\Service\Document;

use Psr\Log\LoggerInterface;

final class PdfExtractor implements ExtractorInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $pdftotextBin = 'pdftotext',
    ) {}

    public function supports(?string $extension, ?string $mimeType): bool
    {
        $ext = strtolower((string)($extension ?? ''));
        if ($ext === 'pdf') { return true; }
        return $mimeType === 'application/pdf';
    }

    public function extract(string $path, ?string $filename = null, ?string $mimeType = null): ExtractedDocument
    {
        if (!is_file($path)) {
            throw new DocumentExtractionException('PDF Pfad existiert nicht.', 'invalid_input');
        }

        $out = tempnam(sys_get_temp_dir(), 'dash_pdf_');
        if ($out === false) {
            throw new DocumentExtractionException('Tempfile konnte nicht erstellt werden.', 'tmp_failed');
        }

        try {
            // -layout: Tabellen/Spalten besser
            // -nopgbrk: keine Pagebreaks
            // -q: quiet
            $cmd = sprintf(
                '%s -layout -nopgbrk -q %s %s 2>&1',
                escapeshellcmd($this->pdftotextBin),
                escapeshellarg($path),
                escapeshellarg($out)
            );

            exec($cmd, $output, $exit);
            $raw = @file_get_contents($out);
            $text = is_string($raw) ? $raw : '';

            if ($exit !== 0) {
                $this->logger->warning('pdftotext failed', [
                    'exit' => $exit,
                    'cmd' => $cmd,
                    'out' => implode("\n", $output),
                    'filename' => $filename,
                ]);
                throw new DocumentExtractionException('PDF Text-Extraktion fehlgeschlagen.', 'pdf_extract_failed');
            }

            $warnings = [];
            $trim = trim($text);

            // OCR-Hint (nur Flag, kein OCR!)
            $needsOcr = mb_strlen($trim) < 120;
            $confidence = $needsOcr ? 0.25 : 0.9;

            if ($needsOcr) {
                $warnings[] = 'PDF wirkt gescannt (wenig Text extrahierbar). OCR kann später die Qualität verbessern.';
            }

            return new ExtractedDocument(
                text: $text,
                warnings: $warnings,
                needsOcr: $needsOcr,
                confidence: $confidence,
                method: 'pdf_pdftotext',
                mimeType: $mimeType ?? 'application/pdf',
                extension: 'pdf',
                filename: $filename,
            );
        } catch (\Throwable $e) {
            if ($e instanceof DocumentExtractionException) {
                throw $e;
            }
            throw new DocumentExtractionException('PDF Extraktion: unerwarteter Fehler.', 'pdf_extract_failed', $e);
        } finally {
            @unlink($out);
        }
    }
}
