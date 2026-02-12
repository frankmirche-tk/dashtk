<?php

declare(strict_types=1);

namespace App\Service\Document;

use PhpOffice\PhpWord\IOFactory;
use Psr\Log\LoggerInterface;

final class DocxExtractor implements ExtractorInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(?string $extension, ?string $mimeType): bool
    {
        $ext = strtolower((string)($extension ?? ''));
        if ($ext === 'docx') { return true; }

        return $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function extract(string $path, ?string $filename = null, ?string $mimeType = null): ExtractedDocument
    {
        if (!is_file($path)) {
            throw new DocumentExtractionException('DOCX Pfad existiert nicht.', 'invalid_input');
        }

        try {
            // PhpWord hat kein 100% “plain text export” API.
            // Pragmatiker-Ansatz: als HTML schreiben und tags strippen.
            $phpWord = IOFactory::load($path, 'Word2007');

            $tmpHtml = tempnam(sys_get_temp_dir(), 'dash_docx_');
            if ($tmpHtml === false) {
                throw new DocumentExtractionException('Tempfile konnte nicht erstellt werden.', 'tmp_failed');
            }

            try {
                $writer = IOFactory::createWriter($phpWord, 'HTML');
                $writer->save($tmpHtml);

                $html = @file_get_contents($tmpHtml);
                $html = is_string($html) ? $html : '';

                // Entferne HEAD/STYLE/SCRIPT, weil strip_tags deren Inhalt als Text übrig lässt
                $html = preg_replace('~<head\b[^>]*>.*?</head>~is', ' ', $html) ?? $html;
                $html = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $html) ?? $html;
                $html = preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $html) ?? $html;

                // Optional: Tabellen/Absätze sinnvoll separieren
                $html = preg_replace('~</(p|div|tr|br|h1|h2|h3|h4|h5|h6)>~i', "\n", $html) ?? $html;
                $html = preg_replace('~</td>~i', " | ", $html) ?? $html;

                $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                // NBSP -> Space
                $text = str_replace("\xC2\xA0", ' ', $text);

                // Mehrfach-Spaces reduzieren, aber Zeilenumbrüche lassen wir erstmal stehen
                $text = preg_replace("/[ \t]{2,}/", ' ', $text) ?? $text;

                // Zeilen mit nur Leerzeichen entfernen
                $text = preg_replace("/\n[ \t]+\n/", "\n\n", $text) ?? $text;
                $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

                // Optional: harte “Klebezeilen” etwas entschärfen (z.B. wenn 150+ chars ohne Space)
                $text = preg_replace("/([A-Za-zÄÖÜäöü])(\d)/u", "$1 $2", $text) ?? $text;  // BuchstabeZahl -> Buchstabe Zahl
                $text = preg_replace("/(\d)([A-Za-zÄÖÜäöü])/u", "$1 $2", $text) ?? $text;  // ZahlBuchstabe -> Zahl Buchstabe


                // Whitespace normalisieren
                $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
                $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

                // Häufige "Klebe-Fälle" aus Briefkopf/Signatur entschärfen:
                // - Wörter direkt aneinander (…GmbHBahnhofstraße…) -> Space dazwischen
                // - Domain/URL direkt an Zahl/Wort -> Space
                // - IBAN/BIC/HRB/Steuer-Nr etc: schon ok, aber die davor/danach getrennt


                // Häufig: "GmbHBahnhofstraße" (Großbuchstabe gefolgt von Großbuchstabe) ist schwer,
                // deshalb zusätzlich spezielle “GmbH” Trennung:
                $text = preg_replace('/\bGmbH(?=\S)/u', 'GmbH ', $text) ?? $text;

                // Zahl<->Wort Trennung (du hast es evtl. schon):
                $text = preg_replace("/([A-Za-zÄÖÜäöü])(\d)/u", "$1 $2", $text) ?? $text;
                $text = preg_replace("/(\d)([A-Za-zÄÖÜäöü])/u", "$1 $2", $text) ?? $text;

                // URLs: www. direkt an Wort klebt
                $text = preg_replace('/(?<!\s)(www\.)/i', ' $1', $text) ?? $text;

                // Bank/IBAN/BIC Keywords: davor Space erzwingen
                $text = preg_replace('/(?<!\s)(IBAN:)/i', ' $1', $text) ?? $text;
                $text = preg_replace('/(?<!\s)(BIC:)/i', ' $1', $text) ?? $text;

                $text = preg_replace('/\bGmb\sH\b/u', 'GmbH', $text) ?? $text;

                // 1) "Gmb H" wieder zusammenziehen (egal ob Space dazwischen oder nicht)
                $text = preg_replace('/\bGmb\s*H\b/u', 'GmbH', $text) ?? $text;

                // 2) Wenn danach direkt Text klebt: "GmbHBahnhofstraße" -> "GmbH Bahnhofstraße"
                $text = preg_replace('/\bGmbH(?=\S)/u', 'GmbH ', $text) ?? $text;



                $warnings = [];
                $needsOcr = mb_strlen($text) < 80; // selten, aber möglich (nur Bilder)
                $confidence = $needsOcr ? 0.35 : 0.9;

                if ($needsOcr) {
                    $warnings[] = 'DOCX enthält sehr wenig extrahierbaren Text (ggf. nur Bilder/Scan). OCR wäre ggf. sinnvoll.';
                }


                return new ExtractedDocument(
                    text: $text,
                    warnings: $warnings,
                    needsOcr: $needsOcr,
                    confidence: $confidence,
                    method: 'docx_phpword_htmlstrip',
                    mimeType: $mimeType ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    extension: 'docx',
                    filename: $filename,
                );
            } finally {
                @unlink($tmpHtml);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('docx extract failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof DocumentExtractionException) {
                throw $e;
            }
            throw new DocumentExtractionException('DOCX Text-Extraktion fehlgeschlagen.', 'docx_extract_failed', $e);
        }
    }
}
