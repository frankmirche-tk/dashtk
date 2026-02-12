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
    /**
     * @param iterable<ExtractorInterface> $extractors
     */
    public function __construct(
        private readonly iterable $extractors,
        private readonly GoogleDriveService $drive,
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

        return $extractor->extract($path, $filename, $mimeType);
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

    private function pickExtractor(?string $extension, ?string $mimeType): ?ExtractorInterface
    {
        foreach ($this->extractors as $ext) {
            if ($ext instanceof ExtractorInterface && $ext->supports($extension, $mimeType)) {
                return $ext;
            }
        }
        // Fallback: falls ext/mime nicht helfen, könnten wir “try parse” machen – lasse ich bewusst weg,
        // damit Fehler transparent bleiben.
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
