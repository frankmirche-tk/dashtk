<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentLoader
{
    public function __construct(
        private GoogleDriveService $drive,
        private PdfTextExtractor $extractor,
        private string $tmpDir, // kommt gleich aus services.yaml
    ) {}

    /**
     * @return array{pdfPath:string, originalName:string, driveFileId:?string}
     */
    public function loadPdf(?string $driveFileId, ?UploadedFile $uploadedFile): array
    {
        if ($driveFileId) {
            $meta = $this->drive->getFileMeta($driveFileId);

            $originalName = $meta['name'] ?? 'drive.pdf';
            $mimeType     = $meta['mimeType'] ?? '';

            $tmpPdf = $this->makeTmpPdfPath($originalName);

            if ($mimeType === 'application/pdf') {
                $this->drive->downloadFileToPath($driveFileId, $tmpPdf);
            } else {
                // Wenn ihr strikt nur PDFs wollt -> später in Resolver: UNSUPPORTED_FILE_TYPE
                // Falls ihr Google Docs zulassen wollt: export als PDF
                $this->drive->exportFileToPath($driveFileId, $tmpPdf, 'application/pdf');
            }

            return [
                'pdfPath' => $tmpPdf,
                'originalName' => $originalName,
                'driveFileId' => $driveFileId,
            ];
        }

        if ($uploadedFile) {
            $originalName = $uploadedFile->getClientOriginalName() ?: 'upload.pdf';

            $tmpPdf = $this->makeTmpPdfPath($originalName);
            $uploadedFile->move(\dirname($tmpPdf), \basename($tmpPdf));

            return [
                'pdfPath' => $tmpPdf,
                'originalName' => $originalName,
                'driveFileId' => null,
            ];
        }

        throw new \RuntimeException('NEED_DRIVE');
    }

    public function extractTextOrThrowScanned(string $pdfPath): string
    {
        $text = $this->extractor->extractToText($pdfPath);

        // Heuristik: sehr wenig Text => Scan ohne Textlayer
        if (mb_strlen(trim($text)) < 50) {
            throw new \RuntimeException('PDF_SCANNED_NEEDS_OCR');
        }

        return $text;
    }

    public function cleanup(?string $path): void
    {
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    private function makeTmpPdfPath(string $originalName): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $originalName) ?: 'doc.pdf';
        if (!str_ends_with(strtolower($safe), '.pdf')) {
            $safe .= '.pdf';
        }

        $dir = rtrim($this->tmpDir, '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir.'/'.uniqid('doc_', true).'_'.$safe;
    }
}
