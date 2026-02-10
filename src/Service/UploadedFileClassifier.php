<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadedFileClassifier
{
    /**
     * @return array{
     *   kind: 'pdf'|'docx'|'xlsx'|'numbers'|'zip_unknown'|'unknown'|'invalid',
     *   ext: string,
     *   mime: string,
     *   code?: string,
     *   message?: string
     * }
     */
    public function classify(UploadedFile $file): array
    {
        $path = $file->getPathname();
        $originalName = (string) $file->getClientOriginalName();
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $head = $this->readHead($path, 16);
        $isPdfMagic = str_starts_with($head, '%PDF-');
        $isZipMagic = str_starts_with($head, "PK\x03\x04");

        $mime = $this->finfoMime($path);

        // Fake-PDF: Endung PDF aber kein PDF-Header
        if ($ext === 'pdf' && !$isPdfMagic) {
            return [
                'kind' => 'invalid',
                'ext' => $ext,
                'mime' => $mime,
                'code' => 'invalid_file_type',
                'message' => 'Die Datei hat zwar die Endung .pdf, ist aber technisch kein PDF (Magic Bytes fehlen). Bitte neu exportieren oder Originalformat hochladen.',
            ];
        }

        // PDF
        if ($isPdfMagic) {
            return [
                'kind' => 'pdf',
                'ext' => $ext,
                'mime' => $mime,
            ];
        }

        // ZIP: DOCX/XLSX/Numbers
        if ($isZipMagic) {
            $zipKind = $this->detectZipOfficeKind($path);

            if ($zipKind !== null) {
                return [
                    'kind' => $zipKind, // docx|xlsx|numbers
                    'ext' => $ext,
                    'mime' => $mime,
                ];
            }

            return [
                'kind' => 'zip_unknown',
                'ext' => $ext,
                'mime' => $mime,
            ];
        }

        return [
            'kind' => 'unknown',
            'ext' => $ext,
            'mime' => $mime,
        ];
    }

    private function readHead(string $path, int $bytes): string
    {
        $fh = @fopen($path, 'rb');
        if (!is_resource($fh)) {
            return '';
        }
        $head = fread($fh, $bytes);
        fclose($fh);

        return is_string($head) ? $head : '';
    }

    private function finfoMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function detectZipOfficeKind(string $path): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        $hasContentTypes = false;
        $hasWord = false;
        $hasXl = false;
        $hasNumbers = false;

        $max = min($zip->numFiles, 500); // Safety
        for ($i = 0; $i < $max; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }

            if ($name === '[Content_Types].xml') {
                $hasContentTypes = true;
            }

            if (str_starts_with($name, 'word/')) {
                $hasWord = true;
            }

            if (str_starts_with($name, 'xl/')) {
                $hasXl = true;
            }

            // Numbers Hinweise (iWork)
            if (str_starts_with($name, 'Index/') || str_contains($name, '.iwa')) {
                $hasNumbers = true;
            }
        }

        $zip->close();

        if ($hasContentTypes && $hasWord) return 'docx';
        if ($hasContentTypes && $hasXl) return 'xlsx';
        if ($hasNumbers) return 'numbers';

        return null;
    }

}
