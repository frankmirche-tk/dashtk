<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Document\DocumentExtractionException;
use App\Service\Document\ExtractedDocument;
use App\Service\Document\ExtractorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentLoader
{
    private const OCR_MIN_TEXT_CHARS = 300; // "praktisch kein Text" -> Threshold (tune bei Bedarf)
    private const OCR_MAX_PAGES = 12;       // Schutz gegen langsame Monster-PDFs

    /**
     * @param iterable<ExtractorInterface> $extractors
     */
    public function __construct(
        private readonly iterable $extractors,
        private readonly GoogleDriveService $drive,
        private readonly OcrPdfService $ocrPdf,
        private readonly LoggerInterface $logger,
    ) {}

    public function extractFromUploadedFile(UploadedFile $file): ExtractedDocument
    {
        $path = $file->getPathname();
        $filename = (string)$file->getClientOriginalName();

        // Mime/Extension nur als Hint – wir verlassen uns zusätzlich auf supports()+parse attempt
        $mimeType = $file->getMimeType() ?: null;
        $ext = strtolower((string)$file->getClientOriginalExtension());

        $extractor = $this->pickExtractor($ext, $mimeType);
        if ($extractor === null) {
            throw new DocumentExtractionException(
                'Dateityp wird nicht unterstützt (erlaubt: PDF, DOCX, XLSX).',
                'invalid_filetype'
            );
        }

        $doc = $extractor->extract($path, $filename, $mimeType);

        // OCR-Fallback NUR für PDF, NUR wenn Text quasi leer oder needsOcr=true
        return $this->maybeApplyOcrPdf(
            sourcePath: $path,
            filename: $filename,
            mimeType: $mimeType,
            ext: $ext,
            original: $doc,
            methodPrefix: 'upload'
        );
    }

    public function extractFromDrive(string $fileId): ExtractedDocument
    {
        $meta = $this->drive->getFileMeta($fileId);

        $name = (string)($meta['name'] ?? 'Drive-Dokument');
        $mime = (string)($meta['mimeType'] ?? '');

        // Google-native export mapping
        $exportMime = null;
        $targetExt = null;

        if ($mime === 'application/vnd.google-apps.document') {
            $exportMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $targetExt = 'docx';
        } elseif ($mime === 'application/vnd.google-apps.spreadsheet') {
            $exportMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            $targetExt = 'xlsx';
        } elseif ($mime === 'application/vnd.google-apps.presentation') {
            // optional später: PPTX
            throw new DocumentExtractionException('Google Slides wird aktuell nicht unterstützt.', 'invalid_filetype');
        }

        // Entscheide Pfad + Download/Export
        $tmp = $this->makeTempPath($targetExt ?? $this->guessExtFromNameOrMime($name, $mime));

        try {
            if ($exportMime !== null) {
                $this->drive->exportFileToPath($fileId, $tmp, $exportMime);
                $ext = $targetExt;
                $mimeType = $exportMime;
                $methodPrefix = 'drive_export';
            } else {
                $this->drive->downloadFileToPath($fileId, $tmp);
                $ext = $this->guessExtFromNameOrMime($name, $mime);
                $mimeType = $mime !== '' ? $mime : null;
                $methodPrefix = 'drive_download';
            }

            $extractor = $this->pickExtractor($ext, $mimeType);
            if ($extractor === null) {
                throw new DocumentExtractionException(
                    'Drive-Dateityp wird nicht unterstützt (erlaubt: PDF, DOCX, XLSX).',
                    'invalid_filetype'
                );
            }

            $doc = $extractor->extract($tmp, $name, $mimeType);

            // OCR-Fallback NUR für PDFs (also auch Drive-PDFs)
            $doc = $this->maybeApplyOcrPdf(
                sourcePath: $tmp,
                filename: $name,
                mimeType: $mimeType,
                ext: $ext,
                original: $doc,
                methodPrefix: $methodPrefix
            );

            // method anreichern (Debuggability)
            return new ExtractedDocument(
                text: $doc->text,
                warnings: $doc->warnings,
                needsOcr: $doc->needsOcr,
                confidence: $doc->confidence,
                method: $methodPrefix . '+' . $doc->method,
                mimeType: $doc->mimeType,
                extension: $doc->extension,
                filename: $doc->filename
            );
        } catch (\Throwable $e) {
            $this->logger->warning('drive extract failed', [
                'fileId' => $fileId,
                'name' => $name,
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof DocumentExtractionException) {
                throw $e;
            }
            throw new DocumentExtractionException('Drive-Dokument konnte nicht verarbeitet werden.', 'extract_failed', $e);
        } finally {
            @unlink($tmp);
        }
    }

    private function maybeApplyOcrPdf(
        string $sourcePath,
        string $filename,
        ?string $mimeType,
        ?string $ext,
        ExtractedDocument $original,
        string $methodPrefix
    ): ExtractedDocument {
        $ext = strtolower((string)$ext);

        // OCR nur für PDFs
        if ($ext !== 'pdf' && ($mimeType !== 'application/pdf')) {
            return $original;
        }

        $textLen = mb_strlen(trim((string)$original->text));
        $shouldOcr = ($original->needsOcr === true) || ($textLen < self::OCR_MIN_TEXT_CHARS);

        if (!$shouldOcr) {
            return $original;
        }

        // PDF OCR'n -> neues PDF -> erneut extrahieren
        $ocrPdfPath = $this->makeTempPath('pdf');

        try {
            $this->logger->info('doc.ocr.start', [
                'filename' => $filename,
                'method' => $methodPrefix,
                'text_len' => $textLen,
                'needs_ocr' => $original->needsOcr,
            ]);

            $this->ocrPdf->ocrPdfToPath(
                inputPdfPath: $sourcePath,
                outputPdfPath: $ocrPdfPath,
                maxPages: self::OCR_MAX_PAGES
            );

            $pdfExtractor = $this->pickExtractor('pdf', 'application/pdf');
            if ($pdfExtractor === null) {
                // Sollte bei dir eigentlich nie passieren, wenn PDF unterstützt wird
                return new ExtractedDocument(
                    text: $original->text,
                    warnings: array_values(array_unique(array_merge($original->warnings, ['OCR: PDF-Extractor nicht verfügbar.']))),
                    needsOcr: $original->needsOcr,
                    confidence: $original->confidence,
                    method: $original->method,
                    mimeType: $original->mimeType,
                    extension: $original->extension,
                    filename: $original->filename
                );
            }

            $ocrDoc = $pdfExtractor->extract($ocrPdfPath, $filename, 'application/pdf');

            $warnings = array_values(array_unique(array_merge(
                $original->warnings,
                ['OCR angewendet (Fallback – Text zu kurz).'],
                $ocrDoc->warnings
            )));

            // Wir geben den OCR-Text zurück, aber behalten die Metadaten konsistent
            return new ExtractedDocument(
                text: $ocrDoc->text,
                warnings: $warnings,
                needsOcr: false, // OCR wurde gemacht
                confidence: $ocrDoc->confidence,
                method: $original->method . '+ocr',
                mimeType: $ocrDoc->mimeType,
                extension: $ocrDoc->extension,
                filename: $ocrDoc->filename
            );
        } catch (\Throwable $e) {
            // OCR ist best-effort. Wenn OCR scheitert, lieber normalen Text nehmen und warnen.
            $this->logger->warning('doc.ocr.failed', [
                'filename' => $filename,
                'method' => $methodPrefix,
                'error' => $e->getMessage(),
            ]);

            return new ExtractedDocument(
                text: $original->text,
                warnings: array_values(array_unique(array_merge($original->warnings, ['OCR fehlgeschlagen (Fallback): ' . $e->getMessage()]))),
                needsOcr: $original->needsOcr, // bleibt wie vorher
                confidence: $original->confidence,
                method: $original->method,
                mimeType: $original->mimeType,
                extension: $original->extension,
                filename: $original->filename
            );
        } finally {
            @unlink($ocrPdfPath);
        }
    }

    private function pickExtractor(?string $extension, ?string $mimeType): ?ExtractorInterface
    {
        foreach ($this->extractors as $ext) {
            if ($ext instanceof ExtractorInterface && $ext->supports($extension, $mimeType)) {
                return $ext;
            }
        }
        return null;
    }

    private function makeTempPath(?string $ext = null): string
    {
        $suffix = $ext ? ('.' . ltrim($ext, '.')) : '';
        return rtrim(sys_get_temp_dir(), '/') . '/dash_' . bin2hex(random_bytes(8)) . $suffix;
    }

    private function guessExtFromNameOrMime(string $name, string $mime): ?string
    {
        $lower = strtolower($name);

        if (str_ends_with($lower, '.pdf') || $mime === 'application/pdf') { return 'pdf'; }
        if (str_ends_with($lower, '.docx') || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') { return 'docx'; }
        if (str_ends_with($lower, '.xlsx') || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') { return 'xlsx'; }

        return null;
    }
}
