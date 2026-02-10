<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Tracing\Trace;
use App\Validator\TKFashionPolicyKeywords;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;


final class FormCreateResolver
{
    private readonly bool $isDev;

    public function __construct(
        private readonly AiChatGateway $aiChat,
        private readonly CacheInterface $cache,
        private readonly Connection $db,
        private readonly LoggerInterface $supportSolutionLogger,
        private readonly PromptTemplateLoader $promptLoader,
        private readonly TKFashionPolicyKeywords $keywordPolicy,
        private readonly UploadedFileClassifier $fileClassifier,   // <—
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
        $driveId  = $this->extractDriveId($driveUrl);

        // ✅ Forms brauchen immer eine Drive-Quelle (auch wenn ein Upload kommt)
        if ($driveId === '') {
            return [
                'type' => ResponseCode::NEED_DRIVE, // oder 'need_drive' wenn ihr keine Const wollt
                'answer' => 'Mir fehlt der Google-Drive Link (Ordner oder Datei). Bitte füge ihn ein, dann kann ich fortfahren.',
                '_meta' => ['ai_used' => false],
            ];
        }

        $hasFile  = $file instanceof UploadedFile;
        $hasDrive = ($driveId !== '') || ($driveUrl !== '');

        $this->supportSolutionLogger->info('form_create.analyze.start', [
            'sessionId' => $sessionId,
            'hasFile' => $hasFile,
            'driveId' => $driveId !== '' ? 'ok' : 'missing',
            'model' => $model,
        ]);

        // 1) Drive-Link nur nötig, wenn KEIN Upload-File mitkommt
        if ($driveId === '' && !$hasFile) {
            return [
                'type' => 'need_drive',
                'answer' => 'Mir fehlt der Google-Drive Link (Ordner oder Datei). Bitte füge ihn ein, dann kann ich fortfahren.',
            ];
        }

        // 2) Mindestens Drive ODER File muss vorhanden sein (Controller-Guard deckt das meist schon ab)
        if (!$hasFile && $driveId === '') {
            return [
                'type' => 'need_input',
                'answer' => 'Bitte lade eine Datei hoch oder füge einen Google-Drive-Link ein.',
            ];
        }

        // 3) Dateityp zentral erkennen (Magic Bytes + ZIP-Inspection)
        if (!$hasFile) {
            return [
                'type' => ResponseCode::NEED_DRIVE,
                'answer' => 'Bitte lade eine Datei hoch oder füge einen Google-Drive Link ein, der direkt abrufbar ist.',
            ];
        }

        $class = $this->fileClassifier->classify($file);

        if ($class['kind'] === 'invalid') {
            return [
                'type' => $class['code'] ?? ResponseCode::INVALID_FILE_TYPE,
                'answer' => $class['message'] ?? 'Ungültiger Dateityp.',
            ];
        }

        if ($class['kind'] !== 'pdf') {
            $hint = match ($class['kind']) {
                'docx' => 'DOCX erkannt. Bitte als PDF exportieren (oder Parser folgt).',
                'xlsx' => 'XLSX erkannt. Bitte als PDF exportieren (oder Parser folgt).',
                'numbers' => 'Apple Numbers erkannt. Bitte als XLSX oder PDF exportieren.',
                default => 'Dateityp nicht unterstützt. Bitte als PDF exportieren.',
            };

            return [
                'type' => ResponseCode::UNSUPPORTED_FILE_TYPE,
                'answer' => $hint . sprintf(
                        ' (kind=%s, ext=%s, mime=%s)',
                        $class['kind'],
                        $class['ext'] ?? '',
                        $class['mime'] ?? ''
                    ),
            ];
        }


        // ab hier: echtes PDF
        $originalName = (string) $file->getClientOriginalName();
        $path = $file->getPathname();


        // 4) PDF -> TEXT
        $text = $this->extractPdfTextWithPdftotext($path);
        $text = trim($text);

        if ($text === '' || mb_strlen($text) < 80) {
            $this->supportSolutionLogger->warning('document_create.analyze.pdf_empty', [
                'sessionId' => $sessionId,
                'filename' => $originalName,
            ]);

            return [
                'type' => 'pdf_scanned_needs_ocr',
                'answer' => 'Dieses PDF enthält keinen auslesbaren Text (wahrscheinlich Scan/Bild-PDF). Bitte OCR aktivieren oder ein textbasiertes PDF hochladen.',
            ];
        }



        $originalName = (string) $file->getClientOriginalName();
        if (!preg_match('/^[a-zA-Z0-9äöüÄÖÜß_\-\. ]+$/u', $originalName)) {
            return [
                'type' => ResponseCode::INVALID_FILENAME,
                'answer' =>
                    "⚠️ Der Dateiname enthält Sonderzeichen oder ungültige Unicode-Zeichen.\n\n"
                    . "Bitte Datei umbenennen (z.B. absage_nach_gespraech.docx) und erneut hochladen.\n"
                    . "Erlaubt: Buchstaben, Zahlen, Leerzeichen, _ - .",
            ];

        }


        $now = $this->nowMidnight();

        // 4) OpenAI Draft bauen (JSON)
        $prompt = $this->buildDocumentPrompt(
            filename: $originalName,
            driveUrl: $driveUrl,
            pdfText: $text,
        );

        $aiContext = [
            'usage_key' => 'support_chat.document_create',
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
        ];

        $tpl = $this->promptLoader->load('FormCreatePrompt.config');
        $history = [
            ['role' => 'system', 'content' => $tpl['system']],
            ['role' => 'user', 'content' => $prompt],
        ];

        $this->supportSolutionLogger->info('form_create.ai.request', [
            'sessionId' => $sessionId,
            'model' => $model,
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
            $this->supportSolutionLogger->error('form_create.ai.failed', [
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
            $this->supportSolutionLogger->warning('document_create.ai.bad_json', [
                'sessionId' => $sessionId,
                'answerPreview' => mb_substr((string)$raw, 0, 500),
            ]);

            return [
                'type' => 'error',
                'answer' => "OpenAI hat kein gültiges JSON geliefert. Bitte dev.log prüfen.\n"
                    . ($this->isDev ? ("Antwort (Preview):\n" . mb_substr((string)$raw, 0, 800)) : ''),
            ];
        }

        // 5) Draft finalisieren (Hardfacts überschreiben/erzwingen)
        $draftId = bin2hex(random_bytes(16));

        $title = trim((string)($json['title'] ?? ''));
        if ($title === '') {
            $title = $this->fallbackTitleFromFilename($originalName);
        }

        // ✅ Symptoms normalisieren: Drive-Link nur anhängen, wenn Drive wirklich vorhanden ist
        $symptoms = $this->normalizeSymptoms(
            (string)($json['symptoms'] ?? ''),
            $hasDrive ? $driveUrl : ''
        );

        $contextNotes = trim((string)($json['context_notes'] ?? ''));

        $keywords = $this->normalizeKeywords($json['keywords'] ?? []);

        // ✅ Media-Felder korrekt setzen:
        // - Drive vorhanden => external/google_drive + URL/ID
        // - nur File => upload, keine external_* Felder
        $mediaType = $hasDrive ? 'external' : 'upload';

        $draft = [
            'type' => 'FORM',
            'title' => $title,
            'symptoms' => $symptoms,
            'context_notes' => $contextNotes !== '' ? $contextNotes : ("Quelle: {$originalName}"),

            'media_type' => 'external',
            'external_media_provider' => 'google_drive',
            'external_media_url' => $driveUrl,
            'external_media_id' => $driveId,

            'created_at' => $now,
            'updated_at' => $now,
            // Dokument: kein Wochen-/Jahresbezug -> published_at = created_at
            'published_at' => $now,
            'category' => 'GENERAL',
            'keywords' => $keywords,
            '_source_filename' => $originalName,
        ];

        $this->storeDraft($draftId, $draft);

        $this->supportSolutionLogger->info('document_create.analyze.ready_for_confirm', [
            'sessionId' => $sessionId,
            'draftId' => $draftId,
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
                'answer' => 'Draft nicht gefunden/abgelaufen. Bitte Dokument erneut analysieren.',
            ];
        }

        $msg = trim($message);

        // Regel: published_at = created_at (und updated_at folgt mit)
        // => wenn User eins davon setzt, ziehen wir die anderen nach
        if (preg_match('/(published_at|created_at)\s*(=|auf)\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $msg, $m)) {
            $date = $m[3] . ' 00:00:00';
            $draft['created_at'] = $date;
            $draft['updated_at'] = $date;
            $draft['published_at'] = $date;
        }

        if (preg_match('~drive[- ]link\s*(=|ist)?\s*(https?://\S+)~i', $msg, $m)) {
            $draft['external_media_url'] = $m[2];
            $draft['external_media_id']  = $this->extractDriveId($m[2]);
        }

        // Optional: Titel patch (simpel)
        if (preg_match('/title\s*(=|auf)\s*(.+)$/i', $msg, $m)) {
            $t = trim((string)$m[2]);
            if ($t !== '') {
                $draft['title'] = $t;
            }
        }

        $this->storeDraft($draftId, $draft);

        $this->supportSolutionLogger->info('document_create.patch', [
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
                'answer' => 'Draft nicht gefunden/abgelaufen. Bitte Dokument erneut analysieren.',
            ];
        }

        $this->supportSolutionLogger->info('document_create.confirm.start', [
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

            $this->supportSolutionLogger->info('document_create.confirm.ok', [
                'sessionId' => $sessionId,
                'draftId' => $draftId,
                'solutionId' => $solutionId,
            ]);

            return [
                'type' => 'ok',
                'answer' => "✅ Dokument wurde eingefügt. ID: {$solutionId}",
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();

            $this->supportSolutionLogger->error('document_create.confirm.failed', [
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

    private function buildDocumentPrompt(string $filename, string $driveUrl, string $pdfText): string
    {
        $pdfText = $this->truncateForPrompt($pdfText, 18000);

        $tpl = $this->promptLoader->load('FormCreatePrompt.config');
                return $this->promptLoader->render($tpl['user'], [
                    'filename' => $filename,
                    'driveUrl' => $driveUrl,
                    'pdfText' => $pdfText,
                ]);
    }

    private function normalizeSymptoms(string $symptoms, string $driveUrl): string
    {
        $symptoms = trim($symptoms);
        $driveUrl = trim($driveUrl);

        // Falls das Modell Mist liefert: minimal retten
        if ($symptoms === '' || mb_strlen($symptoms) < 10) {
            $symptoms = "* Dokument\n";
        }

        // jede Zeile soll mit "* " starten
        $lines = preg_split('/\R/u', $symptoms) ?: [];
        $out = [];

        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '') {
                continue;
            }

            $ln = preg_replace('/^[\-\•\*]\s*/u', '', $ln) ?? $ln;

            // optional: leere Drive-Links rausfiltern
            if ($driveUrl === '' && preg_match('/Dokument\s*\(Drive\)\]\(\s*\)/ui', $ln)) {
                continue;
            }

            $out[] = '* ' . $ln;
        }

        // Drive-Link Bullet nur sicherstellen, wenn driveUrl wirklich vorhanden ist
        if ($driveUrl !== '') {
            $hasDrive = false;

            foreach ($out as $ln) {
                if (str_contains($ln, 'drive.google.com')) {
                    $hasDrive = true;
                    break;
                }
            }

            if (!$hasDrive) {
                $out[] = "* [Dokument (Drive)]({$driveUrl})";
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


    private function fallbackTitleFromFilename(string $filename): string
    {
        $t = trim($filename);
        $t = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $t) ?? $t; // .pdf etc
        $t = str_replace(['_', '-'], ' ', $t);
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return trim($t) !== '' ? trim($t) : 'Dokument';
    }

    // ----------------------------
    // Cache Draft
    // ----------------------------

    private function draftCacheKey(string $draftId): string
    {
        return 'support_chat.form_draft.' . sha1($draftId);
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
            . "- title: **{$draft['title']}**\n"
            . "- category: **{$draft['category']}**\n"
            . "- created_at: **{$draft['created_at']}**\n"
            . "- updated_at: **{$draft['updated_at']}**\n"
            . "- published_at: **{$draft['published_at']}**\n"
            . "- Drive-Link: **{$draft['external_media_url']}**\n"
            . "- Drive-ID: **{$draft['external_media_id']}**\n\n"
            . "Wenn ok, klicke **Einfügen**. Oder schreibe z.B. „created_at auf 2025-12-29“ (setzt published_at automatisch mit).";
    }

    // ----------------------------
    // Helpers (PDF, Dates, JSON, Drive)
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

        // 5) If user pasted only an ID by accident, accept it (practical)
        if (preg_match('~^[a-zA-Z0-9_-]{10,}$~', $driveUrl)) {
            return $driveUrl;
        }

        return '';
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
