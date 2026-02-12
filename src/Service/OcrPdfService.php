<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

final class OcrPdfService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * OCRt ein PDF zu einem neuen PDF (mit Textlayer).
     * Best practice: danach wieder normal mit pdftotext extrahieren.
     */
    public function ocrPdfToPath(string $inputPdfPath, string $outputPdfPath, int $maxPages = 12): void
    {
        if (!is_file($inputPdfPath)) {
            $this->logger->error('ocrmypdf.input_missing', [
                'input' => $inputPdfPath,
            ]);

            throw new \RuntimeException('OCR input file missing.');
        }

        $start = microtime(true);

        $inputSize = @filesize($inputPdfPath);
        $inputSize = is_int($inputSize) ? $inputSize : null;

        // Optional: Seitenlimit über pdfinfo
        $pageLimitArg = $this->buildPagesArg($inputPdfPath, $maxPages);

        $cmd = array_values(array_filter([
            'ocrmypdf',
            '--skip-text',
            '--force-ocr',
            '--rotate-pages',
            '--deskew',
            '--optimize', '1',
            '--language', 'deu+eng',
            '--jobs', '2',
            $pageLimitArg,
            $inputPdfPath,
            $outputPdfPath,
        ], static fn($v) => $v !== null && $v !== ''));

        $this->logger->info('ocrmypdf.start', [
            'input' => $inputPdfPath,
            'input_size' => $inputSize,
            'output' => $outputPdfPath,
            'max_pages' => $maxPages,
            'pages_arg' => $pageLimitArg,
            'cmd' => $cmd,
        ]);

        $process = new Process($cmd);
        $process->setTimeout(90);

        $process->run();

        $exitCode = $process->getExitCode();
        $stdout = trim((string)$process->getOutput());
        $stderr = trim((string)$process->getErrorOutput());

        $durationMs = (int)round((microtime(true) - $start) * 1000);

        $this->logger->info('ocrmypdf.finished', [
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stdout_len' => mb_strlen($stdout),
            'stderr_len' => mb_strlen($stderr),
        ]);

        if (!$process->isSuccessful()) {
            $this->logger->error('ocrmypdf.failed', [
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'stderr' => $stderr,
                'stdout' => $stdout,
            ]);

            $err = $stderr !== '' ? $stderr : $stdout;
            throw new \RuntimeException('ocrmypdf failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        $outSize = @filesize($outputPdfPath);
        $outSize = is_int($outSize) ? $outSize : null;

        if (!is_file($outputPdfPath) || ($outSize !== null && $outSize < 1000)) {
            $this->logger->error('ocrmypdf.output_invalid', [
                'output' => $outputPdfPath,
                'output_size' => $outSize,
            ]);

            throw new \RuntimeException('ocrmypdf produced no valid output.');
        }

        $this->logger->info('ocrmypdf.ok', [
            'output' => $outputPdfPath,
            'output_size' => $outSize,
            'duration_ms' => $durationMs,
        ]);
    }

    private function buildPagesArg(string $pdfPath, int $maxPages): ?string
    {
        $process = new Process(['pdfinfo', $pdfPath]);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning('ocrmypdf.pdfinfo_failed', [
                'pdf' => $pdfPath,
                'exit_code' => $process->getExitCode(),
                'stderr' => trim((string)$process->getErrorOutput()),
            ]);
            return null;
        }

        $out = (string)$process->getOutput();

        if (!preg_match('/Pages:\s+(\d+)/i', $out, $m)) {
            $this->logger->warning('ocrmypdf.pdfinfo_no_pages', [
                'pdf' => $pdfPath,
                'output' => trim($out),
            ]);
            return null;
        }

        $pages = (int)$m[1];

        $this->logger->info('ocrmypdf.pdfinfo_pages', [
            'pdf' => $pdfPath,
            'pages' => $pages,
            'max_pages' => $maxPages,
        ]);

        if ($pages <= 0 || $pages <= $maxPages) {
            return null;
        }

        return '--pages=1-' . $maxPages;
    }
}
