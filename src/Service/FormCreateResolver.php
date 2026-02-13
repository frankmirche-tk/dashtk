<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Tracing\Trace;
use App\Service\Document\DocumentExtractionException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use App\Validator\TKFashionPolicyKeywords;

final class FormCreateResolver
{
    private readonly bool $isDev;

    public function __construct(
        private readonly AiChatGateway $aiChat,
        private readonly CacheInterface $cache,
        private readonly Connection $db,
        private readonly LoggerInterface $supportSolutionLogger,
        private readonly PromptTemplateLoader $promptLoader,
        private readonly DocumentLoader $documentLoader,
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
        ?Trace $trace = null,
        ?string $provider = null,
        ?string $traceId = null,
    ): array {
        $traceId  = $this->ensureTraceId($traceId);
        $provider = $this->normalizeProvider($provider);

        $driveUrl = trim((string)$driveUrl);
        $driveId  = $driveUrl !== '' ? $this->extractDriveId($driveUrl) : '';

        $hasFile  = $file instanceof UploadedFile;
        $hasDrive = $driveUrl !== '';

        if (!$hasFile && !$hasDrive) {
            return $this->errorResponse(
                traceId: $traceId,
                code: 'need_input',
                message: 'Bitte lade eine Datei hoch oder füge einen Google-Drive-Link ein.',
            );
        }

        // Drive-Link vorhanden, aber ID nicht extrahierbar => invalid
        if ($hasDrive && $driveId === '') {
            $this->supportSolutionLogger->warning('form.analyze.drive_url_invalid', [
                'trace_id' => $traceId,
                'drive_url' => mb_substr($driveUrl, 0, 120),
                'drive_url_len' => mb_strlen($driveUrl),
            ]);

            return $this->errorResponse(
                traceId: $traceId,
                code: 'need_drive',
                message: 'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link und kopiere ihn erneut.',
            );
        }

        // Debug (hilft dir sofort im Log zu sehen, was wirklich ankommt)
        $this->supportSolutionLogger->info('form.analyze.drive_debug', [
            'trace_id' => $traceId,
            'drive_url' => mb_substr($driveUrl, 0, 120),
            'drive_url_len' => mb_strlen($driveUrl),
            'drive_id' => $driveId,
            'has_file' => $hasFile,
        ]);

        $normalizedName = '';
        $warnings = [];
        $documentText = '';
        $needsOcrHint = false;

        try {
            if ($hasFile) {
                $normalizedName = $this->normalizeFilename((string)$file->getClientOriginalName());
                $warnings = $this->filenameWarnings($normalizedName);

                $doc = $this->documentLoader->extractFromUploadedFile($file);
                $documentText = (string)$doc->text;
                $needsOcrHint = (bool)$doc->needsOcr;
                $warnings = array_values(array_unique(array_merge($warnings, (array)$doc->warnings)));
            } else {
                // Drive-only
                $doc = $this->documentLoader->extractFromDrive($driveId);
                $normalizedName = $this->normalizeFilename((string)($doc->filename ?? 'Dokument'));
                $warnings = array_values(array_unique(array_merge(
                    $this->filenameWarnings($normalizedName),
                    (array)$doc->warnings
                )));
                $documentText = (string)$doc->text;
                $needsOcrHint = (bool)$doc->needsOcr;
            }
        } catch (\Throwable $e) {
            $this->supportSolutionLogger->warning('form.analyze.extract_failed', [
                'trace_id' => $traceId,
                'drive_id' => $driveId,
                'has_file' => $hasFile,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                traceId: $traceId,
                code: 'extract_failed',
                message: $this->isDev ? ('Extraktion fehlgeschlagen (DEV): '.$e->getMessage()) : 'Dokument konnte nicht verarbeitet werden.',
            );
        }

        $filenameForPrompt = $normalizedName !== '' ? $normalizedName : 'Dokument';

        $tpl = $this->promptLoader->load('FormCreatePrompt.config');

        $system = $this->promptLoader->render((string)($tpl['system'] ?? ''), [
            'today' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
        ]);

        $user = $this->promptLoader->render((string)($tpl['user'] ?? ''), [
            'filename' => $filenameForPrompt,
            'driveUrl' => $driveUrl,
            'message'  => $message,
            'documentText' => $this->truncateForPrompt(trim($documentText), 12000),
            'scan_hint' => $needsOcrHint,
        ]);

        $history = [
            ['role' => 'system', 'content' => $system !== '' ? $system : 'Antworte ausschließlich als JSON.'],
            ['role' => 'user',   'content' => $user],
        ];

        $aiRaw = $this->aiChat->chat(
            history: $history,
            kbContext: '',
            provider: $provider,
            model: $model,
            context: [
                'usage_key' => 'support_chat.form_create.analyze',
                'mode_hint' => 'form_create',
                'trace_id' => $traceId,
                'scan_hint' => $needsOcrHint,
            ]
        );

        $obj = $this->decodeJsonObject($aiRaw);
        if (!is_array($obj)) {
            return $this->errorResponse(
                traceId: $traceId,
                code: 'ai_invalid_response',
                message: 'Ich konnte die Analyse nicht zuverlässig auswerten. Bitte versuche es erneut oder beschreibe den Zweck in 1–2 Sätzen.',
                extra: ['provider' => $provider, 'model' => $model],
            );
        }

        $title = trim((string)($obj['title'] ?? ''));
        if ($title === '') {
            $title = $this->fallbackTitleFromFilename($filenameForPrompt);
        }

        $category = trim((string)($obj['category'] ?? 'GENERAL'));
        $category = $category !== '' ? mb_strtoupper($category) : 'GENERAL';
        if ($category === 'NEWSLETTER') {
            $category = 'GENERAL';
        }

        $symptoms = $this->normalizeSymptoms((string)($obj['symptoms'] ?? ''), $driveUrl);
        $keywords = $this->normalizeKeywords($obj['keywords'] ?? []);
        $keywords = $this->keywordPolicy->filterKeywordObjects($keywords, 20);

        $draftId = 'doc_' . str_replace('-', '', (string)Uuid::uuid4());
        $now = $this->nowMidnight();

        $draft = [
            'type' => 'FORM',
            'title' => $title,
            'symptoms' => $symptoms,
            'context_notes' => (string)($obj['context_notes'] ?? ''),
            'keywords' => $keywords,

            // Media: Drive wenn vorhanden, sonst Upload
            'media_type' => $driveUrl !== '' ? 'external' : 'upload',
            'external_media_provider' => $driveUrl !== '' ? 'gdrive' : null,
            'external_media_url' => $driveUrl !== '' ? $driveUrl : null,
            'external_media_id' => $driveUrl !== '' ? $driveId : null,

            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
            'published_at' => $now->format('Y-m-d H:i:s'),
            'category' => $category,

            'drive_url' => mb_substr($driveUrl, 0, 120),
            'filename' => $normalizedName,
        ];

        $this->storeDraft($draftId, $draft);

        $headerSubtitle = $hasDrive
            ? ($normalizedName !== '' ? "Quelle: Drive • {$normalizedName}" : "Quelle: Drive")
            : ($normalizedName !== '' ? "Quelle: Upload • {$normalizedName}" : "Quelle: Upload");

        return $this->confirmResponse(
            traceId: $traceId,
            code: 'needs_confirmation',
            answer: $this->renderConfirmText($draft),
            draftId: $draftId,
            confirmCard: $this->buildConfirmCard(
                kind: 'form',
                draftId: $draftId,
                category: $category,
                headerTitle: $title,
                headerSubtitle: $headerSubtitle,
                fields: $draft,
                warnings: $warnings,
            ),
            extra: ['provider' => $provider, 'model' => $model]
        );
    }



    public function patch(
        string $sessionId,
        string $draftId,
        string $message,
        string $provider = 'openai',
        ?string $model = null,
        ?Trace $trace = null,
        ?string $traceId = null,
    ): array {
        $traceId = $this->ensureTraceId($traceId);
        $provider = $this->normalizeProvider($provider);

        $draft = $this->loadDraft($draftId);
        if (!is_array($draft)) {
            return $this->errorResponse($traceId, 'draft_missing', 'Draft nicht gefunden/abgelaufen. Bitte Dokument erneut analysieren.');
        }

        $tpl = $this->promptLoader->load('FormPatchPrompt.config');
        $prompt = $this->promptLoader->render((string)$tpl['user'], [
            'draft' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'message' => $message,
        ]);

        $history = [
            ['role' => 'system', 'content' => 'Du bist ein Assistenzsystem. Antworte ausschließlich als JSON (Patch auf Draft).'],
            ['role' => 'user', 'content' => $prompt],
        ];

        $aiRaw = $this->aiChat->chat(
            history: $history,
            kbContext: '',
            provider: $provider,
            model: $model,
            context: [
                'usage_key' => 'support_chat.form_create.patch',
                'mode_hint' => 'form_create_patch',
                'trace_id' => $traceId,
            ]
        );

        $obj = $this->decodeJsonObject($aiRaw);
        if (!is_array($obj)) {
            return $this->errorResponse($traceId, 'ai_invalid_response', 'Ich konnte die Änderungen nicht zuverlässig auswerten. Bitte formuliere die Änderung noch einmal.', [
                'provider' => $provider, 'model' => $model
            ]);
        }

        $patched = $draft;

        if (isset($obj['title']) && is_string($obj['title'])) {
            $t = trim($obj['title']);
            if ($t !== '') { $patched['title'] = $t; }
        }

        if (isset($obj['symptoms']) && is_string($obj['symptoms'])) {
            $patched['symptoms'] = $this->normalizeSymptoms($obj['symptoms'], (string)($draft['drive_url'] ?? ''));
        }

        if (isset($obj['keywords'])) {
            $patched['keywords'] = $this->normalizeKeywords($obj['keywords']);
        }

        if (isset($obj['category']) && is_string($obj['category'])) {
            $c = trim($obj['category']);
            if ($c !== '') {
                $c = mb_strtoupper($c);
                if ($c !== 'NEWSLETTER') {
                    $patched['category'] = $c;
                }
            }
        }

        // enforce Drive always
        $driveUrl = trim((string)($draft['drive_url'] ?? ''));
        $driveId = trim((string)($draft['external_media_id'] ?? ''));
        if ($driveUrl === '' || $driveId === '') {
            return $this->errorResponse($traceId, 'need_drive', 'Drive-Link fehlt im Draft. Bitte analysiere das Dokument erneut mit gültigem Drive-Link.');
        }

        $patched['media_type'] = 'external';
        $patched['external_media_provider'] = 'gdrive';
        $patched['external_media_url'] = $driveUrl;
        $patched['external_media_id'] = $driveId;
        $patched['updated_at'] = $this->nowMidnight()->format('Y-m-d H:i:s');

        $this->storeDraft($draftId, $patched);

        $filename = (string)($patched['filename'] ?? '');
        $warnings = $filename !== '' ? $this->filenameWarnings($this->normalizeFilename($filename)) : [];

        return $this->confirmResponse(
            traceId: $traceId,
            code: 'needs_confirmation',
            answer: 'Änderungen übernommen. Bitte prüfen und bestätigen.',
            draftId: $draftId,
            confirmCard: $this->buildConfirmCard(
                kind: 'form',
                draftId: $draftId,
                category: (string)($patched['category'] ?? 'GENERAL'),
                headerTitle: (string)($patched['title'] ?? 'Dokument'),
                headerSubtitle: $filename !== '' ? "Quelle: Drive + Upload • {$filename}" : 'Quelle: Drive',
                fields: $patched,
                warnings: $warnings,
            ),
            extra: ['provider' => $provider, 'model' => $model]
        );
    }

    public function confirm(
        string $sessionId,
        string $draftId,
        ?string $traceId = null
    ): array {
        $traceId = $this->ensureTraceId($traceId);
        $draft = $this->loadDraft($draftId);

        if (!is_array($draft)) {
            return $this->errorResponse($traceId, 'draft_missing', 'Draft nicht gefunden/abgelaufen. Bitte Dokument erneut analysieren.');
        }

        $category = trim((string)($draft['category'] ?? 'GENERAL'));
        $category = $category !== '' ? mb_strtoupper($category) : 'GENERAL';
        if ($category === 'NEWSLETTER') {
            $category = 'GENERAL';
        }

        $driveUrl = trim((string)($draft['external_media_url'] ?? ''));
        $driveId = trim((string)($draft['external_media_id'] ?? ''));
        if ($driveUrl === '' || $driveId === '') {
            return $this->errorResponse($traceId, 'need_drive', 'Drive-Link fehlt. Bitte analysiere das Dokument erneut mit gültigem Drive-Link.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->insert('support_solution', [
                'type' => $draft['type'], // FORM
                'title' => $draft['title'],
                'symptoms' => $draft['symptoms'],
                'context_notes' => $draft['context_notes'],
                'media_type' => $draft['media_type'], // external
                'external_media_provider' => $draft['external_media_provider'], // gdrive
                'external_media_url' => $draft['external_media_url'],
                'external_media_id' => $draft['external_media_id'],
                'created_at' => $draft['created_at'],
                'updated_at' => $draft['updated_at'],
                'published_at' => $draft['created_at'],
                'category' => $category,
            ]);

            $solutionId = (int)$this->db->lastInsertId();

            foreach (($draft['keywords'] ?? []) as $kw) {
                if (!is_array($kw)) { continue; }
                $keyword = trim((string)($kw['keyword'] ?? ''));
                if ($keyword === '') { continue; }
                $this->db->insert('support_solution_keyword', [
                    'solution_id' => $solutionId,
                    'keyword' => $keyword,
                    'weight' => (int)($kw['weight'] ?? 1),
                ]);
            }

            $this->db->commit();
            $this->cache->delete($this->draftCacheKey($draftId));

            return $this->answerResponse($traceId, 'ok', "✅ Dokument wurde gespeichert (ID: {$solutionId}).", [
                'draftId' => $draftId
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return $this->errorResponse(
                $traceId,
                'db_insert_failed',
                $this->isDev ? ('DB Insert fehlgeschlagen (DEV): ' . $e->getMessage()) : 'Speichern fehlgeschlagen. Bitte später erneut versuchen.'
            );
        }
    }

    // ----------------------------
    // ConfirmCard + Response Helpers
    // ----------------------------

    private function buildConfirmCard(
        string $kind,
        string $draftId,
        string $category,
        string $headerTitle,
        string $headerSubtitle,
        array $fields,
        array $warnings = [],
    ): array {
        return [
            'kind' => $kind,
            'draftId' => $draftId,
            'category' => $category,
            'header' => ['title' => $headerTitle, 'subtitle' => $headerSubtitle],
            'fields' => $fields,
            'warnings' => array_values(array_filter(array_map('strval', $warnings))),
            'actions' => ['patch', 'confirm'],
        ];
    }

    private function answerResponse(string $traceId, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'type' => 'answer',
            'answer' => $message,
            'trace_id' => $traceId,
            'code' => $code,
        ], $extra);
    }

    private function confirmResponse(string $traceId, string $code, string $answer, string $draftId, array $confirmCard, array $extra = []): array
    {
        return array_merge([
            'type' => 'confirm',
            'answer' => $answer,
            'trace_id' => $traceId,
            'code' => $code,
            'draftId' => $draftId,
            'confirmCard' => $confirmCard,
        ], $extra);
    }

    private function errorResponse(string $traceId, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'type' => 'error',
            'answer' => $message,
            'trace_id' => $traceId,
            'code' => $code,
        ], $extra);
    }

    // ----------------------------
    // Normalizer / Helpers
    // ----------------------------

    private function buildDocumentPrompt(string $filename, string $driveUrl, string $message, string $pdfText): string
    {
        $pdfText = $this->truncateForPrompt($pdfText, 12000);
        $tpl = $this->promptLoader->load('DocumentCreatePrompt.config');

        return $this->promptLoader->render((string)$tpl['user'], [
            'filename' => $filename,
            'driveUrl' => $driveUrl,
            'message' => $message,
            'pdfText' => $pdfText,
        ]);
    }

    private function normalizeSymptoms(string $symptoms, string $driveUrl): string
    {
        // Policy: exactly one Drive bullet, never empty.
        $s = trim($symptoms);
        $s = preg_replace('/\r\n?/', "\n", $s) ?? $s;

        $lines = $s === '' ? [] : explode("\n", $s);
        $filtered = [];
        foreach ($lines as $line) {
            if (stripos($line, 'drive.google.com') !== false || stripos($line, 'docs.google.com') !== false) {
                continue;
            }
            $filtered[] = $line;
        }

        $s = trim(implode("\n", $filtered));
        if ($s !== '') { $s .= "\n"; }

        $driveUrl = trim($driveUrl);
        if ($driveUrl !== '') {
            $s .= "* [Dokument (Drive)]({$driveUrl})";
        }

        return trim($s);
    }

    private function normalizeKeywords(mixed $keywords): array
    {
        $items = [];

        if (is_array($keywords)) {
            foreach ($keywords as $kw) {
                if (is_string($kw)) {
                    $k = trim(mb_strtolower($kw));
                    if ($k !== '') { $items[] = ['keyword' => $k, 'weight' => 1]; }
                    continue;
                }
                if (is_array($kw)) {
                    $k = trim(mb_strtolower((string)($kw['keyword'] ?? '')));
                    if ($k === '') { continue; }
                    $w = (int)($kw['weight'] ?? 1);
                    if ($w <= 0) { $w = 1; }
                    $items[] = ['keyword' => $k, 'weight' => $w];
                }
            }
        }

        $stop = ['und','oder','der','die','das','ein','eine','mit','für','von','im','in','am','an','auf','zu','zum','zur','bei','ist','sind'];
        $map = [];
        foreach ($items as $it) {
            $k = preg_replace('/\s+/', ' ', (string)$it['keyword']) ?? (string)$it['keyword'];
            $k = trim($k);
            if ($k === '' || in_array($k, $stop, true)) { continue; }
            $map[$k] = max($map[$k] ?? 0, (int)$it['weight']);
        }

        $out = [];
        foreach ($map as $k => $w) {
            $out[] = ['keyword' => $k, 'weight' => $w];
        }

        if (count($out) > 18) {
            $out = array_slice($out, 0, 18);
        }

        return $out;
    }

    private function fallbackTitleFromFilename(string $filename): string
    {
        $f = trim($filename);
        if ($f === '') { return 'Dokument'; }
        $f = preg_replace('/\.(pdf|docx|xlsx)$/iu', '', $f) ?? $f;
        $f = preg_replace('/[_\-]+/', ' ', $f) ?? $f;
        $f = trim($f);
        return $f !== '' ? $f : 'Dokument';
    }

    private function draftCacheKey(string $draftId): string
    {
        return 'support_chat.form_create.draft.' . sha1($draftId);
    }


    private function storeDraft(string $draftId, array $draft): void
    {
        $key = $this->draftCacheKey($draftId);

        // optional: vorher löschen, damit alte Werte keine Rolle spielen
        $this->cache->delete($key);

        $this->cache->get($key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($draft) {
            $item->expiresAfter(3600);
            return $draft;
        });
    }

    private function loadDraft(string $draftId): ?array
    {
        $key = $this->draftCacheKey($draftId);

        $val = $this->cache->get($key, static function (\Symfony\Contracts\Cache\ItemInterface $item) {
            return null;
        });

        return is_array($val) ? $val : null;
    }



    private function renderConfirmText(array $draft): string
    {
        $title = (string)($draft['title'] ?? '');
        $category = (string)($draft['category'] ?? 'GENERAL');

        $source = 'Upload';
        $driveUrl = trim((string)($draft['drive_url'] ?? ''));
        if ($driveUrl !== '') {
            $source = 'Google Drive';
        }

        return implode("\n", [
            "Bitte prüfen und bestätigen:",
            "- Titel: {$title}",
            "- Kategorie: {$category}",
            "- Quelle: {$source}",
        ]);
    }



    private function extractDriveId(string $url): string
    {
        $raw = trim($url);
        if ($raw === '') {
            return '';
        }

        // 0) Falls UI nur eine reine ID liefert (oder mit Whitespace)
        // Drive IDs sind typischerweise >= 10 Zeichen, erlaubte Charset: A-Z a-z 0-9 _ -
        if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $raw)) {
            return $raw;
        }

        // Normalisieren: manche UIs geben nur einen Pfad zurück wie "/<id>/edit?...".
        // Wir versuchen zuerst parse_url, und falls das fehlschlägt, prefixen wir eine Dummy-Domain.
        $u = $raw;
        $parts = @parse_url($u);
        if (!is_array($parts) || (!isset($parts['host']) && str_starts_with($u, '/'))) {
            $u = 'https://dummy.local' . $u;
            $parts = @parse_url($u);
        }

        // 1) Query: id=...
        if (is_array($parts) && isset($parts['query'])) {
            parse_str((string) $parts['query'], $q);
            if (isset($q['id']) && is_string($q['id']) && $q['id'] !== '' && preg_match('/^[a-zA-Z0-9_-]{10,}$/', $q['id'])) {
                return $q['id'];
            }
        }

        $path = '';
        if (is_array($parts) && isset($parts['path']) && is_string($parts['path'])) {
            $path = $parts['path'];
        } else {
            $path = $raw;
        }

        // 2) Standard: /file/d/<id> /document/d/<id> /spreadsheets/d/<id> /presentation/d/<id>
        if (preg_match('~/(?:file|document|spreadsheets|presentation)/d/([a-zA-Z0-9_-]{10,})~', $path, $m)) {
            return (string) $m[1];
        }

        // 3) Folders: /drive/folders/<id>
        if (preg_match('~/drive/folders/([a-zA-Z0-9_-]{10,})~', $path, $m)) {
            return (string) $m[1];
        }

        // 4) Manche Links: /uc?export=download&id=<id> oder /uc?id=<id>
        // (id=... hatten wir oben schon – aber falls parse_url kaputt war)
        if (preg_match('~[?&]id=([a-zA-Z0-9_-]{10,})~', $raw, $m)) {
            return (string) $m[1];
        }

        // 5) UI-Problemfall: "/<id>/edit" (ohne /d/)
        if (preg_match('~^/([a-zA-Z0-9_-]{10,})/(?:edit|view|preview)(?:/|$)~', $path, $m)) {
            return (string) $m[1];
        }

        // 6) Fallback: irgendein "/d/<id>" (kommt bei manchen Share-Varianten vor)
        if (preg_match('~/d/([a-zA-Z0-9_-]{10,})~', $raw, $m)) {
            return (string) $m[1];
        }

        return '';
    }







    private function nowMidnight(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->setTime(0, 0, 0);
    }

    private function extractPdfTextWithPdftotext(string $path): string
    {
        $cmd = 'pdftotext -layout ' . escapeshellarg($path) . ' -';
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : '';
    }

    private function truncateForPrompt(string $text, int $maxChars): string
    {
        $t = trim($text);
        if (mb_strlen($t) <= $maxChars) { return $t; }
        return mb_substr($t, 0, $maxChars) . "\n\n[...TRUNCATED...]";
    }

    private function decodeJsonObject(string $raw): ?array
    {
        $s = trim($raw);
        $obj = json_decode($s, true);
        if (is_array($obj)) { return $obj; }
        if (preg_match('/\{.*\}/s', $s, $m)) {
            $obj = json_decode((string)$m[0], true);
            if (is_array($obj)) { return $obj; }
        }
        return null;
    }

    private function ensureTraceId(?string $traceId): string
    {
        $traceId = trim((string)$traceId);
        return $traceId !== '' ? $traceId : (string)Uuid::uuid4();
    }

    private function normalizeProvider(?string $provider): ?string
    {
        $p = trim((string)$provider);
        if ($p === '') { return null; }
        return mb_strtolower($p);
    }

    private function normalizeFilename(string $name): string
    {
        $n = trim($name);
        if ($n === '') { return $n; }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($n, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $n = $normalized;
            }
        }

        return str_replace("\0", '', $n);
    }

    /**
     * @return list<string>
     */
    private function filenameWarnings(string $filename): array
    {
        if ($filename === '') { return []; }

        $w = [];
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            $w[] = 'Dateiname enthält Pfadzeichen. Bitte umbenennen (keine /, \\, ..).';
            return $w;
        }

        if (!preg_match('/^[\p{L}\p{N} äöüÄÖÜß_\-\.]+$/u', $filename)) {
            $w[] = 'Dateiname enthält Sonderzeichen, die Probleme verursachen können. Bitte umbenennen (nur Buchstaben/Zahlen/_/-).';
        }

        if (mb_strlen($filename) > 120) {
            $w[] = 'Dateiname ist sehr lang. Bitte ggf. kürzen.';
        }

        return $w;
    }
}
