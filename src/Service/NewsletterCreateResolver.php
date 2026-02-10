<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Tracing\Trace;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Doctrine\DBAL\Connection;
use App\Validator\TKFashionPolicyKeywords;


final class NewsletterCreateResolver
{
    private readonly bool $isDev;

    public function __construct(
        private readonly AiChatGateway $aiChat,
        private readonly CacheInterface $cache,
        private readonly Connection $db,
        private readonly LoggerInterface $supportSolutionLogger,
        private readonly PromptTemplateLoader $promptLoader,
        private readonly TKFashionPolicyKeywords $keywordPolicy,
        KernelInterface $kernel,
    ) {
        $this->isDev = $kernel->getEnvironment() === 'dev';
    }

    public function analyze(
        string $sessionId,
        string $message,
        string $driveUrl,
        ?UploadedFile $file,
        ?string $model = null,
        ?Trace $trace = null
    ): array {
        $driveUrl = trim($driveUrl);
        $driveId = $this->extractDriveId($driveUrl);

        $this->supportSolutionLogger->info('newsletter_create.analyze.start', [
            'sessionId' => $sessionId,
            'hasFile' => $file instanceof UploadedFile,
            'driveId' => $driveId !== '' ? 'ok' : 'missing',
            'model' => $model,
        ]);

        // 1) Drive-Link Pflicht
        if ($driveId === '' && !$file instanceof UploadedFile) {
            return [
                'type' => 'need_drive',
                'answer' => 'Mir fehlt der Google-Drive Link (Ordner oder Datei). Bitte füge ihn ein, dann kann ich fortfahren.',
            ];
        }

        // 2) File Pflicht
        if (!$file instanceof UploadedFile) {
            return [
                'type' => 'error',
                'answer' => 'Kein PDF hochgeladen. Bitte Datei auswählen und erneut senden.',
            ];
        }
        // 2.1) Newsletter akzeptiert NUR echte PDFs (Magic Bytes)
        $path = $file->getPathname();
        $fh = @fopen($path, 'rb');
        $head = $fh ? fread($fh, 8) : '';
        if (is_resource($fh)) {
            fclose($fh);
        }
        $head = is_string($head) ? $head : '';

        $isPdf = str_starts_with($head, '%PDF-');
        $ext = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));

        // Fake PDFs (Endung .pdf aber kein echtes PDF) und alle Nicht-PDFs hart blocken
        if (!$isPdf) {
            $this->supportSolutionLogger->warning('newsletter_create.analyze.not_pdf', [
                'sessionId' => $sessionId,
                'filename' => $file->getClientOriginalName(),
                'ext' => $ext,
                'mime' => $file->getMimeType(),
                'head' => bin2hex($head),
            ]);

            return [
                'type' => ResponseCode::UNSUPPORTED_FILE_TYPE,
                'answer' => 'Vorlage ist kein Newsletter PDF',
            ];
        }

        // Optional streng: wenn du wirklich NUR .pdf zulassen willst (auch wenn Inhalt PDF ist)
        if ($ext !== 'pdf') {
            $this->supportSolutionLogger->warning('newsletter_create.analyze.not_pdf_extension', [
                'sessionId' => $sessionId,
                'filename' => $file->getClientOriginalName(),
                'ext' => $ext,
                'mime' => $file->getMimeType(),
            ]);

            return [
                'type' => ResponseCode::UNSUPPORTED_FILE_TYPE,
                'answer' => 'Vorlage ist kein Newsletter PDF',
            ];
        }


        // 3) PDF -> TEXT
        $text = $this->extractPdfTextWithPdftotext($file->getPathname());
        $text = trim($text);

        if ($text === '') {
            $this->supportSolutionLogger->warning('newsletter_create.analyze.pdf_empty', [
                'sessionId' => $sessionId,
                'filename' => $file->getClientOriginalName(),
            ]);

            return [
                'type' => 'error',
                'answer' => 'PDF-Text-Extraktion fehlgeschlagen (pdftotext liefert leer). Bitte PDF prüfen oder Parser wechseln.',
            ];
        }

        if (!$this->looksLikeNewsletter($text)) {
            return [
                'type' => ResponseCode::UNSUPPORTED_TEMPLATE,
                'answer' => 'Vorlage ist kein Newsletter PDF',
            ];
        }


        // 4) Jahr/KW aus Dateiname
        $originalName = (string) $file->getClientOriginalName();
        $parsed = $this->parseYearKwFromFilename($originalName);

        $year = $parsed['year'];
        $kw = $parsed['kw'];

        if (!is_int($year) || $year < 2000 || $year > 2100 || !is_int($kw) || $kw < 1 || $kw > 53) {
            $this->supportSolutionLogger->warning('newsletter_create.analyze.filename_parse_failed', [
                'sessionId' => $sessionId,
                'filename' => $originalName,
                'parsed' => $parsed,
            ]);

            return [
                'type' => ResponseCode::INVALID_FILENAME,
                'answer' =>
                    "Konnte **Jahr/KW** nicht sicher aus dem Dateinamen lesen.\n"
                    . "Erwartet z.B.: `Newsletter_2025_KW53.pdf`.\n"
                    . "Dateiname war: `{$parsed['raw']}`",
            ];
        }

        $publishedAt = $this->mondayOfIsoWeek($year, $kw)->format('Y-m-d 00:00:00');
        $now = $this->nowMidnight();

        // 5) OpenAI Draft bauen (JSON)
        $prompt = $this->buildNewsletterPrompt(
            filename: $originalName,
            year: $year,
            kw: $kw,
            driveUrl: $driveUrl,
            pdfText: $text,
        );

        $aiContext = [
            'usage_key' => 'support_chat.newsletter_create',
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
        ];

        $tpl = $this->promptLoader->load('NewsletterCreatePrompt.config');
                $history = [
                        ['role' => 'system', 'content' => $tpl['system']],
                        ['role' => 'user', 'content' => $prompt],
                    ];

        $this->supportSolutionLogger->info('newsletter_create.ai.request', [
            'sessionId' => $sessionId,
            'model' => $model,
            'kw' => $kw,
            'year' => $year,
            'textChars' => mb_strlen($text),
        ]);

        try {
            $raw = $this->aiChat->chat(
                history: $history,
                kbContext: '',
                provider: 'openai',
                model: $model,
                context: $aiContext
            );
        } catch (\Throwable $e) {
            $this->supportSolutionLogger->error('newsletter_create.ai.failed', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return [
                'type' => 'error',
                'answer' => $this->isDev
                    ? ('OpenAI Fehler (DEV): ' . $e->getMessage())
                    : 'OpenAI Anfrage fehlgeschlagen. Bitte dev.log prüfen.',
            ];
        }

        $json = $this->decodeJsonObject($raw);
        if (!is_array($json)) {
            $this->supportSolutionLogger->warning('newsletter_create.ai.bad_json', [
                'sessionId' => $sessionId,
                'answerPreview' => mb_substr((string)$raw, 0, 500),
            ]);

            return [
                'type' => 'error',
                'answer' => "OpenAI hat kein gültiges JSON geliefert. Bitte dev.log prüfen.\n"
                    . ($this->isDev ? ("Antwort (Preview):\n" . mb_substr((string)$raw, 0, 800)) : ''),
            ];
        }

        // 6) Draft finalisieren (Hardfacts überschreiben/erzwingen)
        $draftId = bin2hex(random_bytes(16));

        $symptoms = $this->normalizeSymptoms((string)($json['symptoms'] ?? ''), $driveUrl);
        $contextNotes = trim((string)($json['context_notes'] ?? ''));

        $keywords = $this->normalizeKeywords($json['keywords'] ?? [], $kw, $year);

        // ✅ Media-Felder für Newsletter:
        // Newsletter sind immer "external" (Google Drive). Wenn kein Drive-Link vorhanden ist,
        // dann sauber abbrechen (weil sonst später die Quelle fehlt).
        if ($driveId === '') {
            return [
                'type' => ResponseCode::NEED_DRIVE,
                'answer' => 'Mir fehlt der Google-Drive Link (Ordner oder Datei). Bitte füge ihn ein, dann kann ich fortfahren.',
            ];
        }

        $draft = [
            'type' => 'FORM',
            'title' => "Newsletter KW {$kw}/{$year}",
            'symptoms' => $symptoms,
            'context_notes' => $contextNotes !== '' ? $contextNotes : ("Quelle: {$originalName}"),

            'media_type' => 'external',
            'external_media_provider' => 'google_drive',
            'external_media_url' => $driveUrl,
            'external_media_id' => $driveId,

            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $publishedAt,

            'newsletter_year' => $year,
            'newsletter_kw' => $kw,
            'newsletter_edition' => 'STANDARD',
            'category' => 'NEWSLETTER',
            'keywords' => $keywords,
            '_source_filename' => $originalName,
        ];

        $this->storeDraft($draftId, $draft);

        $this->supportSolutionLogger->info('newsletter_create.analyze.ready_for_confirm', [
            'sessionId' => $sessionId,
            'draftId' => $draftId,
            'kw' => $kw,
            'year' => $year,
            'keywords' => count($keywords),
            'symptomsChars' => mb_strlen($symptoms),
            'contextChars' => mb_strlen($draft['context_notes']),
        ]);

        return [
            'type' => ResponseCode::NEEDS_CONFIRMATION,
            'answer' => $this->renderConfirmText($draft),
            'draftId' => $draftId,
            'confirmCard' => [
                'draftId' => $draftId,
                'fields' => $draft,
            ],
            '_meta' => [
                'ai_used' => true,
            ],
        ];

    }

    public function patch(
        string $sessionId,
        string $draftId,
        string $message
    ): array {
        $draft = $this->loadDraft($draftId);

        if (!is_array($draft)) {
            return [
                'type' => 'error',
                'answer' => 'Draft nicht gefunden/abgelaufen. Bitte Newsletter erneut analysieren.',
            ];
        }

        $msg = trim($message);

        // bewusst simpel (du kannst das später AI-gestützt machen)
        if (preg_match('/published_at\s*(=|auf)\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $msg, $m)) {
            $draft['published_at'] = $m[2] . ' 00:00:00';
        }
        if (preg_match('/created_at\s*(=|auf)\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $msg, $m)) {
            $draft['created_at'] = $m[2] . ' 00:00:00';
            $draft['updated_at'] = $m[2] . ' 00:00:00';
        }
        if (preg_match('~drive[- ]link\s*(=|ist)?\s*(https?://\S+)~i', $msg, $m)) {
            $draft['external_media_url'] = $m[2];
            $draft['external_media_id'] = $this->extractDriveId($m[2]);
        }

        $this->storeDraft($draftId, $draft);

        $this->supportSolutionLogger->info('newsletter_create.patch', [
            'sessionId' => $sessionId,
            'draftId' => $draftId,
        ]);

        return [
            'type' => 'needs_confirmation',
            'answer' => $this->renderConfirmText($draft),
            'draftId' => $draftId,
            'confirmCard' => [
                'draftId' => $draftId,
                'fields' => $draft,
            ],
        ];
    }

    public function confirm(
        string $sessionId,
        string $draftId
    ): array {
        $draft = $this->loadDraft($draftId);

        if (!is_array($draft)) {
            return [
                'type' => 'error',
                'answer' => 'Draft nicht gefunden/abgelaufen. Bitte Newsletter erneut analysieren.',
            ];
        }

        $this->supportSolutionLogger->info('newsletter_create.confirm.start', [
            'sessionId' => $sessionId,
            'draftId' => $draftId,
        ]);

        $this->db->beginTransaction();
        try {
            $this->db->insert('support_solution', [
                'type' => $draft['type'],
                'title' => $draft['title'],
                'symptoms' => $draft['symptoms'],
                'context_notes' => $draft['context_notes'],
                'media_type' => $draft['media_type'],
                'external_media_provider' => $draft['external_media_provider'],
                'external_media_url' => $draft['external_media_url'],
                'external_media_id' => $draft['external_media_id'],
                'created_at' => $draft['created_at'],
                'updated_at' => $draft['updated_at'],
                'published_at' => $draft['published_at'],
                'newsletter_year' => (int) $draft['newsletter_year'],
                'newsletter_kw' => (int) $draft['newsletter_kw'],
                'newsletter_edition' => $draft['newsletter_edition'],
                'category' => $draft['category'],
            ]);

            $solutionId = (int) $this->db->lastInsertId();

            foreach (($draft['keywords'] ?? []) as $kw) {
                $this->db->insert('support_solution_keyword', [
                    'solution_id' => $solutionId,
                    'keyword' => (string) $kw['keyword'],
                    'weight' => (int) $kw['weight'],
                ]);
            }

            $this->db->commit();
            $this->cache->delete($this->draftCacheKey($draftId));

            $this->supportSolutionLogger->info('newsletter_create.confirm.ok', [
                'sessionId' => $sessionId,
                'draftId' => $draftId,
                'solutionId' => $solutionId,
            ]);

            return [
                'type' => 'ok',
                'answer' => "✅ Newsletter wurde eingefügt. ID: {$solutionId}",
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();

            $this->supportSolutionLogger->error('newsletter_create.confirm.failed', [
                'sessionId' => $sessionId,
                'draftId' => $draftId,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw $e;
        }
    }

    // ----------------------------
    // Prompt / Normalizer
    // ----------------------------

    private function buildNewsletterPrompt(string $filename, int $year, int $kw, string $driveUrl, string $pdfText): string
    {
        $pdfText = $this->truncateForPrompt($pdfText, 18000);

        $tpl = $this->promptLoader->load('NewsletterCreatePrompt.config');
                return $this->promptLoader->render($tpl['user'], [
                    'filename' => $filename,
                    'year' => $year,
                    'kw' => $kw,
                    'driveUrl' => $driveUrl,
                    'pdfText' => $pdfText,
                ]);
    }

    private function normalizeSymptoms(string $symptoms, string $driveUrl): string
    {
        $symptoms = trim($symptoms);

        // Falls das Modell Mist liefert: minimal retten
        if ($symptoms === '' || mb_strlen($symptoms) < 10) {
            $symptoms = "* Newsletter\n";
        }

        // jede Zeile soll mit "* " starten
        $lines = preg_split('/\R/u', $symptoms) ?: [];
        $out = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $ln = preg_replace('/^[\-\•\*]\s*/u', '', $ln) ?? $ln;

            // 1) Leere Markdown-Links killen: [Text]() oder [Text](   )
            if (preg_match('/\[[^\]]+\]\(\s*\)/u', $ln)) {
                continue;
            }

            // 2) Optional: falls Modell sowas wie "(Drive-Ordner)()" liefert
            if (preg_match('/\(\s*\)\s*$/u', $ln)) {
                continue;
            }

            $out[] = '* ' . $ln;

        }

        // Drive-Link Bullet sicherstellen
        $driveUrl = trim($driveUrl);

        // Drive-Link Bullet nur wenn driveUrl wirklich vorhanden ist
        if ($driveUrl !== '') {
            $hasDrive = false;
            foreach ($out as $ln) {
                if (str_contains($ln, 'drive.google.com')) {
                    $hasDrive = true;
                    break;
                }
            }
            if (!$hasDrive) {
                $out[] = "* [Newsletter (Drive-Ordner)]({$driveUrl})";
            }
        }


        return implode("\n", $out);
    }

    /**
     * @param mixed $keywords
     * @return array<int, array{keyword:string, weight:int}>
     */
    private function normalizeKeywords(mixed $keywords): array
    {
        if (!is_array($keywords)) {
            return [];
        }

        // Delegiere Normalisierung + Filterung komplett an die Policy
        return $this->keywordPolicy->filterKeywordObjects($keywords, 20);
    }

    // ----------------------------
    // Cache Draft
    // ----------------------------

    private function draftCacheKey(string $draftId): string
    {
        return 'support_chat.newsletter_draft.' . sha1($draftId);
    }

    private function storeDraft(string $draftId, array $draft): void
    {
        $key = $this->draftCacheKey($draftId);

        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $i) use ($draft) {
            $i->expiresAfter(1800);
            return $draft;
        });
    }

    private function loadDraft(string $draftId): mixed
    {
        $key = $this->draftCacheKey($draftId);
        return $this->cache->get($key, static fn(ItemInterface $i) => null);
    }

    private function renderConfirmText(array $draft): string
    {
        return "Bitte prüfe vor dem Einfügen:\n\n"
            . "- created_at: **{$draft['created_at']}**\n"
            . "- updated_at: **{$draft['updated_at']}**\n"
            . "- published_at: **{$draft['published_at']}**\n"
            . "- Drive-Link: **{$draft['external_media_url']}**\n"
            . "- Drive-ID: **{$draft['external_media_id']}**\n\n"
            . "Wenn ok, klicke **Einfügen**. Oder schreibe z.B. „published_at auf 2025-12-29“.";
    }

    // ----------------------------
    // Helpers (PDF, Filename, Dates, JSON)
    // ----------------------------

    private function extractDriveId(string $driveUrl): string
    {
        $driveUrl = trim($driveUrl);
        if ($driveUrl === '') {
            return '';
        }

        // Normalize (Browser / Windows / Copy-Paste)
        $driveUrl = str_replace('\\', '/', $driveUrl);

        // 1) Folder URL
        // https://drive.google.com/drive/folders/<ID>
        // https://drive.google.com/folders/<ID>
        if (preg_match('~/(?:drive/)?folders/([a-zA-Z0-9_-]+)~', $driveUrl, $m)) {
            return $m[1];
        }

        // 2) File URL
        // https://drive.google.com/file/d/<ID>/view?usp=drivesdk
        // https://drive.google.com/file/d/<ID>/view?usp=drive_link
        if (preg_match('~/file/d/([a-zA-Z0-9_-]+)~', $driveUrl, $m)) {
            return $m[1];
        }

        // 3) Open-by-id URL
        // https://drive.google.com/open?id=<ID>
        if (preg_match('~[?&]id=([a-zA-Z0-9_-]+)~', $driveUrl, $m)) {
            return $m[1];
        }

        // 4) UC URL (direct download/view)
        // https://drive.google.com/uc?id=<ID>&export=download
        if (preg_match('~/uc\?id=([a-zA-Z0-9_-]+)~', $driveUrl, $m)) {
            return $m[1];
        }

        // 5) "sharing" links sometimes appear in other formats; as last resort:
        // If user pasted only an ID by accident, accept it (optional, but practical)
        if (preg_match('~^[a-zA-Z0-9_-]{10,}$~', $driveUrl)) {
            return $driveUrl;
        }

        return '';
    }


    /**
     * Akzeptiert:
     * - Newsletter_2025_KW53.pdf
     * - Newsletter_2025_KW53_Teil2.pdf
     * - Newsletter_2025_KW53_Sondernewsletter.pdf
     * - Newsletter-2025-KW53-irgendwas.pdf
     */
    private function parseYearKwFromFilename(string $filename): array
    {
        $raw = $filename;
        $name = trim($filename);

        // Normalisieren
        $name = str_replace([' ', '__'], ['_', '_'], $name);

        $year = null;
        $kw = null;

        if (preg_match('/newsletter[_-]?(\d{4})[_-]?kw\s*([0-9]{1,2})/i', $name, $m)) {
            $year = (int)$m[1];
            $kw = (int)$m[2];
        } elseif (preg_match('/(\d{4}).*kw\s*([0-9]{1,2})/i', $name, $m)) {
            // Fallback: irgendwo im Namen
            $year = (int)$m[1];
            $kw = (int)$m[2];
        }

        return [
            'raw' => $raw,
            'year' => $year,
            'kw' => $kw,
        ];
    }

    private function looksLikeNewsletter(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        // zu kurz => sehr wahrscheinlich kein Newsletter
        if (mb_strlen($t) < 800) {
            return false;
        }

        // starke Indikatoren (mind. 1 Treffer)
        $needles = [
            'newsletter',
            'kw ',
            'kalenderwoche',
            'filiale',
            'filiale des monats',
            'umsatz',
            'bonwert',
            'gewinnspiel',
            'promotion',
        ];

        foreach ($needles as $n) {
            if (str_contains($t, $n)) {
                return true;
            }
        }

        // optional: eure Filialcodes als Indikator (mind. 1)
        if (preg_match('/\b(lpdo|lpsa|cola|cosu)\b/i', $text)) {
            return true;
        }

        return false;
    }


    private function mondayOfIsoWeek(int $year, int $kw): \DateTimeImmutable
    {
        $dt = new \DateTimeImmutable();
        return $dt->setISODate($year, $kw, 1)->setTime(0, 0, 0);
    }

    private function nowMidnight(): string
    {
        return (new \DateTimeImmutable('now'))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    }

    private function extractPdfTextWithPdftotext(string $path): string
    {
        $bin = 'pdftotext';
        $cmd = escapeshellcmd($bin) . ' -layout ' . escapeshellarg($path) . ' -';
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : '';
    }

    private function truncateForPrompt(string $s, int $maxChars): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $maxChars) return $s;
        return mb_substr($s, 0, $maxChars) . "\n\n[TRUNCATED]";
    }

    private function decodeJsonObject(string $raw): ?array
    {
        $raw = trim($raw);

        // häufige Modelle packen JSON in ```json ... ```
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
