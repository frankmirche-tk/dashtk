<?php

declare(strict_types=1);

namespace App\Service\Document;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;

final class XlsxExtractor implements ExtractorInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxChars = 40000,
        private readonly int $maxRowsPerSheet = 500,
    ) {}

    public function supports(?string $extension, ?string $mimeType): bool
    {
        $ext = strtolower((string)($extension ?? ''));
        if ($ext === 'xlsx') { return true; }

        return $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function extract(string $path, ?string $filename = null, ?string $mimeType = null): ExtractedDocument
    {
        if (!is_file($path)) {
            throw new DocumentExtractionException('XLSX Pfad existiert nicht.', 'invalid_input');
        }

        try {
            $spreadsheet = IOFactory::load($path);

            $parts = [];
            $warnings = [];
            $totalChars = 0;
            $truncated = false;

            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $sheetName = (string)$sheet->getTitle();
                $parts[] = "=== Sheet: {$sheetName} ===";

                $rows = $sheet->toArray(null, true, true, true);
                $rowCount = 0;

                foreach ($rows as $row) {
                    $rowCount++;
                    if ($rowCount > $this->maxRowsPerSheet) {
                        $warnings[] = "XLSX: Sheet '{$sheetName}' gekürzt (Row-Limit).";
                        break;
                    }

                    // Flatten row: "A|B|C"
                    $cells = [];
                    foreach ($row as $cell) {
                        $v = trim((string)$cell);
                        if ($v !== '') {
                            $cells[] = $v;
                        }
                    }
                    if ($cells === []) {
                        continue;
                    }

                    $line = implode(' | ', $cells);
                    $parts[] = $line;

                    $totalChars += mb_strlen($line) + 1;
                    if ($totalChars > $this->maxChars) {
                        $truncated = true;
                        $warnings[] = 'XLSX: Inhalt gekürzt (Text-Limit).';
                        break 2;
                    }
                }
            }

            $text = trim(implode("\n", $parts));

            if ($text === '') {
                $warnings[] = 'XLSX enthält keinen extrahierbaren Text (leer oder nur Formatierung).';
            }
            if ($truncated) {
                // already warned
            }

            return new ExtractedDocument(
                text: $text,
                warnings: $warnings,
                needsOcr: false,          // OCR bei XLSX idR nicht sinnvoll
                confidence: $text !== '' ? 0.9 : 0.4,
                method: 'xlsx_phpspreadsheet_flatten',
                mimeType: $mimeType ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                extension: 'xlsx',
                filename: $filename,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('xlsx extract failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof DocumentExtractionException) {
                throw $e;
            }
            throw new DocumentExtractionException('XLSX Text-Extraktion fehlgeschlagen.', 'xlsx_extract_failed', $e);
        }
    }
}
