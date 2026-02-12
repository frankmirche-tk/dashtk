<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Tracing\Trace;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
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

        $driveUrl = trim($driveUrl);
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

        // Wenn Drive-Link angegeben wurde, dann muss er auch gültig sein
        if ($hasDrive && $driveId === '') {
            return $this->errorResponse(
                traceId: $traceId,
                code: 'need_drive',
                message: 'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link und kopiere ihn erneut.',
            );
        }

        $doc = null;

        $normalizedName = '';
        $warnings = [];
        $pdfText = '';
        $isScannedHint = false;

        // Newsletter-Metadaten (Resolver entscheidet!)
        $year = 0;
        $kw = 0;
        $edition = 'STANDARD'; // string enum: STANDARD | TEIL_1 | TEIL_2 | SPECIAL ...
        $publishedAt = null; // \DateTimeImmutable|null

        try {
            // 1) PDF laden: Drive preferred, sonst Upload
            if ($driveId !== '') {
                $doc = $this->documentLoader->loadPdf($driveId, $file);
                $path = (string)($doc['pdfPath'] ?? '');
                if ($path === '' || !is_file($path)) {
                    return $this->errorResponse(
                        traceId: $traceId,
                        code: 'drive_url_unreachable',
                        message: 'Der Google-Drive-Link ist nicht abrufbar (Berechtigung/kein Direktdownload). Bitte Freigabe prüfen oder eine Datei hochladen.',
                    );
                }
                $normalizedName = $this->normalizeFilename((string)($doc['originalName'] ?? 'Newsletter.pdf'));
            } else {
                // Upload-only
                if (!$file instanceof UploadedFile) {
                    return $this->errorResponse(
                        traceId: $traceId,
                        code: 'need_input',
                        message: 'Bitte lade eine Datei hoch oder füge einen Google-Drive-Link ein.',
                    );
                }
                $path = $file->getPathname();
                $normalizedName = $this->normalizeFilename((string)$file->getClientOriginalName());
            }

            $warnings = $this->filenameWarnings($normalizedName);

            // 2) PDF Magic Bytes (echtes PDF)
            $fh = @fopen($path, 'rb');
            $head = $fh ? fread($fh, 8) : '';
            if (is_resource($fh)) { fclose($fh); }
            $head = is_string($head) ? $head : '';

            if (!str_starts_with($head, '%PDF-')) {
                return $this->errorResponse(
                    traceId: $traceId,
                    code: 'invalid_filetype',
                    message: 'Die Datei ist kein echtes PDF. Bitte nutze ein gültiges PDF.',
                );
            }

            // 3) Text extrahieren + Scan-Hinweis
            try {
                // wenn docLoader vorhanden und Drive genutzt wurde: nutzen, sonst fallback pdftotext
                if ($driveId !== '' && method_exists($this->documentLoader, 'extractTextOrThrowScanned')) {
                    $pdfText = $this->documentLoader->extractTextOrThrowScanned($path);
                } else {
                    $pdfText = $this->extractPdfTextWithPdftotext($path);
                    if (mb_strlen(trim($pdfText)) < 120) {
                        throw new \RuntimeException('PDF_SCANNED_NEEDS_OCR');
                    }
                }
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'PDF_SCANNED_NEEDS_OCR') {
                    $isScannedHint = true;
                    $warnings[] = 'PDF wirkt gescannt (wenig Text extrahierbar). OCR kann später die Qualität verbessern.';
                    $pdfText = '';
                } else {
                    throw $e;
                }
            }

            // 4) Year/KW/Edition ermitteln (Resolver!)
            [$yFromFn, $kwFromFn] = $this->parseYearKwFromFilename($normalizedName);
            $year = $yFromFn;
            $kw   = $kwFromFn;

            // Optional: aus Text ziehen, falls Dateiname nicht hilft
            if (($year <= 0 || $kw <= 0) && $pdfText !== '') {
                $t = mb_strtolower($pdfText);
                // Beispiele: "KW 07/2026", "KW07 2026", "Kalenderwoche 7 2026"
                if (preg_match('/\bkw\s*0?([1-9]|[1-4]\d|5[0-3])\b.*?\b([12]\d{3})\b/u', $t, $m)) {
                    $kw = (int)$m[1];
                    $year = (int)$m[2];
                } elseif (preg_match('/\b([12]\d{3})\b.*?\bkw\s*0?([1-9]|[1-4]\d|5[0-3])\b/u', $t, $m)) {
                    $year = (int)$m[1];
                    $kw = (int)$m[2];
                }
            }

            // Fallback: aktuelle ISO-Woche/Jahr (nur wenn sonst nichts)
            if ($year <= 0 || $kw <= 0) {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
                $year = (int)$now->format('o'); // ISO year
                $kw   = (int)$now->format('W');
                $warnings[] = 'Jahr/KW konnten nicht sicher aus Dateiname/Text erkannt werden. Fallback: aktuelle KW verwendet.';
            }

            // Edition immer als String/Enum, Default STANDARD.
            // Quelle: erst (ggf.) GUI, dann Dateiname, dann Titel, dann PDF-Text.
            $edition = $this->resolveNewsletterEdition(
                guiEdition: null, // aktuell habt ihr im Analyze-Flow kein GUI-Feld; später ggf. $obj['newsletter_edition'] ?? null
                filename: $normalizedName,
                title: $message,  // falls ihr den "Titel" im Message-Text habt; sonst '' lassen
                pdfText: $pdfText
            );


            // published_at = Montag der ISO-KW
            $publishedAt = $this->mondayOfIsoWeek($year, $kw);

            $title = "Newsletter KW {$kw}/{$year}";
            if (is_string($edition) && preg_match('/^TEIL_(\d+)$/', $edition, $m)) {
                $title .= " – Teil " . (int)$m[1];
            } elseif ($edition === 'SPECIAL') {
                $title .= " – Special";
            }


            // 5) Prompt bauen (KI liefert NUR symptoms/context_notes/keywords)
            $tpl = $this->promptLoader->load('NewsletterCreatePrompt.config');

            $system = $this->promptLoader->render((string)($tpl['system'] ?? ''), [
                'today' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
            ]);

            $user = $this->promptLoader->render((string)($tpl['user'] ?? ''), [
                'filename' => ($normalizedName !== '' ? $normalizedName : 'Newsletter.pdf'),
                'year' => $year,
                'kw' => $kw,
                'driveUrl' => $driveUrl !== '' ? $driveUrl : '#',
                'pdfText' => trim($pdfText),
            ]);

            $history = [
                ['role' => 'system', 'content' => $system !== '' ? $system : 'Antworte ausschließlich als JSON. Keine Extras.'],
                ['role' => 'user', 'content' => $user],
            ];

            $aiRaw = $this->aiChat->chat(
                history: $history,
                kbContext: '',
                provider: $provider,
                model: $model,
                context: [
                    'usage_key' => 'support_chat.newsletter_create.analyze',
                    'mode_hint' => 'newsletter_create',
                    'trace_id' => $traceId,
                    'scan_hint' => $isScannedHint,
                ]
            );

            $obj = $this->decodeJsonObject($aiRaw);
            if (!is_array($obj)) {
                return $this->errorResponse(
                    traceId: $traceId,
                    code: 'ai_invalid_response',
                    message: 'Ich konnte die Newsletter-Analyse nicht zuverlässig auswerten. Bitte versuche es erneut.',
                    extra: ['provider' => $provider, 'model' => $model],
                );
            }

            // 6) KI-Felder übernehmen (NUR diese!)
            $symptomsRaw = is_string($obj['symptoms'] ?? null) ? (string)$obj['symptoms'] : '';
            $contextNotes = is_string($obj['context_notes'] ?? null) ? (string)$obj['context_notes'] : '';
            $keywords = $this->normalizeKeywords($obj['keywords'] ?? []);
            $keywords = $this->keywordPolicy->filterKeywordObjects($keywords, 20);

            if (count($keywords) > 20) {
                $keywords = array_slice($keywords, 0, 20);
            }

            // symptoms normalisieren + finaler Newsletter-Link (genau 1x)
            $symptoms = trim($symptomsRaw);
            $symptoms = preg_replace('/\r\n?/', "\n", $symptoms) ?? $symptoms;

            // nur *-Zeilen behalten, leere raus
            $lines = $symptoms === '' ? [] : explode("\n", $symptoms);
            $clean = [];
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '') continue;
                // entferne alte Drive/Newsletter-Links aus KI-Antwort (wird unten neu gesetzt)
                if (stripos($ln, 'drive.google.com') !== false) continue;
                if (preg_match('/^\*\s*\[newsletter\s*kw/i', $ln)) continue;

                // erzwinge "* "
                $ln = preg_replace('/^\*\s*/u', '* ', $ln) ?? $ln;
                if (!str_starts_with($ln, '* ')) {
                    $ln = '* ' . ltrim($ln, "-•\t ");
                }
                $clean[] = $ln;
            }

            $kwLabel = (string)$kw; // ohne 0-padding im Link-Text war bei euch ok
            $linkUrl = $driveUrl !== '' ? $driveUrl : '#';
            $clean[] = "* [Newsletter KW{$kwLabel} ({$year}) (Drive-Ordner)]({$linkUrl})";

            $symptoms = trim(implode("\n", $clean));

            // 7) Draft bauen (Resolver entscheidet ALLES außer symptoms/context/keywords)
            $draftId = 'doc_' . str_replace('-', '', (string)Uuid::uuid4());
            $now = $this->nowMidnight();

            $draft = [
                // Business-Regel: Newsletter sind in support_solution immer type="FORM"
                'type' => 'FORM',
                'title' => $title,
                'symptoms' => $symptoms,
                'context_notes' => $contextNotes,
                'keywords' => $keywords,


                // Media: Drive wenn vorhanden, sonst Upload
                'media_type' => $driveUrl !== '' ? 'external' : 'upload',
                'external_media_provider' => $driveUrl !== '' ? 'gdrive' : null,
                'external_media_url' => $driveUrl !== '' ? $driveUrl : null,
                'external_media_id' => $driveUrl !== '' ? $driveId : null,

                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'published_at' => $publishedAt?->format('Y-m-d H:i:s'),
                'newsletter_year' => $year,
                'newsletter_kw' => $kw,
                'newsletter_edition' => $edition, // nie NULL, immer STANDARD/TEIL_X/SPECIAL
                'category' => 'NEWSLETTER',
                'drive_url' => $driveUrl,
                'filename' => $normalizedName,
            ];

            $this->storeDraft($draftId, $draft);

            $headerTitle = "Newsletter {$year} • KW" . str_pad((string)$kw, 2, '0', STR_PAD_LEFT);
            $headerSubtitle = ($driveUrl !== '')
                ? ($normalizedName !== '' ? "Quelle: Google Drive • {$normalizedName}" : "Quelle: Google Drive")
                : ($normalizedName !== '' ? "Quelle: Upload • {$normalizedName}" : "Quelle: Upload");

            $answerLines = [];
            $answerLines[] = "Bitte prüfen und bestätigen:";
            $answerLines[] = "- Titel: {$title}";
            $answerLines[] = "- Ausgabe: {$year} / KW" . str_pad((string)$kw, 2, '0', STR_PAD_LEFT);
            if (is_string($edition) && preg_match('/^TEIL_(\d+)$/', $edition, $m)) {
                $answerLines[] = "- Edition: Teil " . (int)$m[1];
            } elseif ($edition === 'SPECIAL') {
                $answerLines[] = "- Edition: Special";
            } else {
                $answerLines[] = "- Edition: STANDARD";
            }

            $answerLines[] = "- Kategorie: NEWSLETTER";
            $answer = implode("\n", $answerLines);

            return $this->confirmResponse(
                traceId: $traceId,
                code: 'needs_confirmation',
                answer: $answer,
                draftId: $draftId,
                confirmCard: $this->buildConfirmCard(
                    kind: 'newsletter',
                    draftId: $draftId,
                    category: 'NEWSLETTER',
                    headerTitle: $headerTitle,
                    headerSubtitle: $headerSubtitle,
                    fields: $draft,
                    warnings: $warnings,
                ),
                extra: ['provider' => $provider, 'model' => $model]
            );
        } finally {
            // Drive-Download cleanup (nur wenn documentLoader genutzt wurde)
            if (is_array($doc) && !empty($doc['pdfPath'])) {
                $this->documentLoader->cleanup((string)$doc['pdfPath']);
            }
        }
    }



    /**
     * Newsletter-spezifisch:
     * - Entfernt alle vorhandenen Drive-Links aus symptoms
     * - Erzwingt am Ende exakt:
     *   * [Newsletter KW{{kw}} ({{year}}) (Drive-Ordner)]({{driveUrl}})
     */
    private function normalizeNewsletterSymptoms(string $symptoms, string $driveUrl, int $year, int $kw): string
    {
        $s = trim($symptoms);
        $s = preg_replace('/\r\n?/', "\n", $s) ?? $s;

        $lines = $s === '' ? [] : explode("\n", $s);
        $filtered = [];
        foreach ($lines as $line) {
            if (stripos($line, 'drive.google.com') !== false) {
                continue;
            }
            $filtered[] = rtrim($line);
        }

        $s = trim(implode("\n", $filtered));
        if ($s !== '') { $s .= "\n"; }

        $driveUrl = trim($driveUrl);
        if ($driveUrl !== '') {
            $kw2 = str_pad((string)$kw, 2, '0', STR_PAD_LEFT);
            $s .= "* [Newsletter KW{$kw2} ({$year}) (Drive-Ordner)]({$driveUrl})";
        }

        return trim($s);
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
            return $this->errorResponse($traceId, 'draft_missing', 'Draft nicht gefunden/abgelaufen. Bitte Newsletter erneut analysieren.');
        }

        $tpl = $this->promptLoader->load('NewsletterPatchPrompt.config');
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
                'usage_key' => 'support_chat.newsletter_create.patch',
                'mode_hint' => 'newsletter_create_patch',
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

        if (isset($obj['published_at']) && is_string($obj['published_at'])) {
            $p = trim($obj['published_at']);
            if ($p !== '') { $patched['published_at'] = $p; }
        }

        if (array_key_exists('newsletter_edition', $obj)) {
            $ed = is_string($obj['newsletter_edition']) ? trim($obj['newsletter_edition']) : '';
            $patched['newsletter_edition'] = ($ed !== '' ? $ed : null);
        }

        // hard rules
        $patched['category'] = 'NEWSLETTER';
        $patched['type'] = 'NEWSLETTER';
        $patched['updated_at'] = $this->nowMidnight()->format('Y-m-d H:i:s');

        $this->storeDraft($draftId, $patched);

        $year = (int)($patched['newsletter_year'] ?? 0);
        $kw = (int)($patched['newsletter_kw'] ?? 0);
        $filename = (string)($patched['filename'] ?? 'Newsletter.pdf');

        $headerTitle = ($year > 0 && $kw > 0)
            ? "Newsletter {$year} • KW" . str_pad((string)$kw, 2, '0', STR_PAD_LEFT)
            : "Newsletter (Draft)";
        $headerSubtitle = "Quelle: Upload • {$filename}";

        return $this->confirmResponse(
            traceId: $traceId,
            code: 'needs_confirmation',
            answer: 'Änderungen übernommen. Bitte prüfen und bestätigen.',
            draftId: $draftId,
            confirmCard: $this->buildConfirmCard(
                kind: 'newsletter',
                draftId: $draftId,
                category: 'NEWSLETTER',
                headerTitle: $headerTitle,
                headerSubtitle: $headerSubtitle,
                fields: $patched,
                warnings: $this->filenameWarnings($this->normalizeFilename($filename)),
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
            return $this->errorResponse($traceId, 'draft_missing', 'Draft nicht gefunden/abgelaufen. Bitte Newsletter erneut analysieren.');
        }

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
                'newsletter_year' => (int)$draft['newsletter_year'],
                'newsletter_kw' => (int)$draft['newsletter_kw'],
                'newsletter_edition' => $draft['newsletter_edition'],
                'category' => $draft['category'],
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

            return $this->answerResponse($traceId, 'ok', "✅ Newsletter wurde gespeichert (ID: {$solutionId}).", [
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
    // Prompt / Normalizer / Helpers
    // ----------------------------

    private function buildNewsletterPrompt(string $filename, int $year, int $kw, string $driveUrl, string $pdfText): string
    {
        $pdfText = $this->truncateForPrompt($pdfText, 18000);
        $tpl = $this->promptLoader->load('NewsletterCreatePrompt.config');

        return $this->promptLoader->render((string)$tpl['user'], [
            'filename' => $filename,
            'year' => $year,
            'kw' => $kw,
            'driveUrl' => $driveUrl,
            'pdfText' => $pdfText,
        ]);
    }

    private function renderConfirmText(array $draft): string
    {
        $title = (string)($draft['title'] ?? '');
        $year = (int)($draft['newsletter_year'] ?? 0);
        $kw = (int)($draft['newsletter_kw'] ?? 0);

        $lines = [];
        $lines[] = "Bitte prüfen und bestätigen:";
        $lines[] = "- Titel: {$title}";
        if ($year > 0 && $kw > 0) {
            $lines[] = "- Ausgabe: {$year} / KW" . str_pad((string)$kw, 2, '0', STR_PAD_LEFT);
        }
        $lines[] = "- Kategorie: NEWSLETTER";
        return implode("\n", $lines);
    }

    private function normalizeSymptoms(string $symptoms, string $driveUrl): string
    {
        $s = trim($symptoms);
        $s = preg_replace('/\r\n?/', "\n", $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;

        $driveUrl = trim($driveUrl);
        if ($driveUrl !== '') {
            $lines = $s === '' ? [] : explode("\n", $s);
            $filtered = [];
            foreach ($lines as $line) {
                if (stripos($line, 'drive.google.com') !== false) {
                    continue;
                }
                $filtered[] = $line;
            }
            $s = trim(implode("\n", $filtered));
            $s = ($s !== '' ? $s . "\n" : '');
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

        // dedupe + simple stopwords + trim/limits
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

        // limit
        if (count($out) > 18) {
            $out = array_slice($out, 0, 18);
        }

        return $out;
    }

    private function draftCacheKey(string $draftId): string
    {
        return 'support_chat.newsletter_create.draft.' . sha1($draftId);
    }

    private function storeDraft(string $draftId, array $draft): void
    {
        $key = $this->draftCacheKey($draftId);
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($draft) {
            $item->expiresAfter(3600);
            return $draft;
        });
    }

    private function loadDraft(string $draftId): ?array
    {
        $key = $this->draftCacheKey($draftId);
        $val = $this->cache->get($key, static fn(ItemInterface $item) => null);
        return is_array($val) ? $val : null;
    }

    private function extractDriveId(string $url): string
    {
        $url = trim($url);
        if ($url === '') { return ''; }
        if (preg_match('~drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)~', $url, $m)) { return (string)$m[1]; }
        if (preg_match('~drive\.google\.com\/open\?id=([a-zA-Z0-9_-]+)~', $url, $m)) { return (string)$m[1]; }
        if (preg_match('~drive\.google\.com\/drive\/folders\/([a-zA-Z0-9_-]+)~', $url, $m)) { return (string)$m[1]; }
        return '';
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseYearKwFromFilename(string $filename): array
    {
        $fn = trim($filename);

        if (preg_match('/newsletter[\s_\-]*([12]\d{3})[\s_\-]*kw\s*([0-5]\d)\.pdf$/iu', $fn, $m)) {
            $year = (int)$m[1];
            $kw = (int)$m[2];
            if ($kw >= 1 && $kw <= 53) {
                return [$year, $kw];
            }
        }

        if (preg_match('/^Newsletter_([12]\d{3})_KW([0-5]\d)\.pdf$/u', $fn, $m)) {
            $year = (int)$m[1];
            $kw = (int)$m[2];
            if ($kw >= 1 && $kw <= 53) {
                return [$year, $kw];
            }
        }

        return [0, 0];
    }

    private function resolveNewsletterEdition(?string $guiEdition, string $filename, string $title, string $pdfText = ''): string
    {
        // 1) GUI hat Vorrang (falls ihr später im Flow eine Edition mitschickt)
        $guiEdition = is_string($guiEdition) ? trim($guiEdition) : '';
        if ($guiEdition !== '') {
            return $this->normalizeNewsletterEdition($guiEdition);
        }

        // 2) Dateiname ist die wichtigste Quelle
        $fromFile = $this->editionFromFilename($filename);
        if ($fromFile !== '') {
            return $fromFile;
        }

        // 3) Fallback: Titel (letztes Token)
        $fromTitle = $this->editionFromTitle($title);
        if ($fromTitle !== '') {
            return $fromTitle;
        }

        // 4) Optionaler Fallback: PDF-Text (nur am Anfang, damit es nicht teuer wird)
        $pdfText = trim($pdfText);
        if ($pdfText !== '') {
            $head = mb_substr($pdfText, 0, 2000);
            if (preg_match('/\bteil\s*0*([1-9]\d?)\b/iu', $head, $m)) {
                return 'TEIL_' . (int)$m[1];
            }
            if (preg_match('/\bpart\s*0*([1-9]\d?)\b/iu', $head, $m)) {
                return 'TEIL_' . (int)$m[1];
            }
            if (preg_match('/\bspecial\b/iu', $head)) {
                return 'SPECIAL';
            }
        }

        return 'STANDARD';
    }

    private function editionFromFilename(string $filename): string
    {
        $name = trim($filename);
        if ($name === '') return '';

        $base = pathinfo($name, PATHINFO_FILENAME); // ohne .pdf

        // Alles nach KWxx ist Kandidat für Edition
        // Beispiele:
        // Newsletter_2026_KW5_Teil2.pdf   => suffix "Teil2"
        // Newsletter_2026_KW05_SPECIAL.pdf => suffix "SPECIAL"
        if (!preg_match('/\bkw\s*0?([1-9]|[1-4]\d|5[0-3])\b(.*)$/iu', $base, $m)) {
            return ''; // kein KW gefunden => keine Edition ableitbar
        }

        $suffix = trim((string)($m[2] ?? ''), " _-");
        if ($suffix === '') {
            return 'STANDARD';
        }

        return $this->normalizeNewsletterEdition($suffix);
    }

    private function editionFromTitle(string $title): string
    {
        $t = trim($title);
        if ($t === '') return '';

        // letzter "Token" nach Space/_/-
        $parts = preg_split('/[\s_\-]+/u', $t);
        if (!$parts || count($parts) === 0) return '';

        $last = trim((string)end($parts));
        if ($last === '') return '';

        return $this->normalizeNewsletterEdition($last);
    }

    private function normalizeNewsletterEdition(string $raw): string
    {
        $s = strtoupper(trim($raw));

        // STANDARD
        if ($s === 'STANDARD') return 'STANDARD';

        // SPECIAL
        if (str_contains($s, 'SPECIAL')) return 'SPECIAL';

        // TEIL2 / TEIL_2 / TEIL-2 / PART2 / PART_2
        if (preg_match('/\b(TEIL|PART)[_\- ]*0*([1-9]\d*)\b/iu', $s, $m)) {
            return 'TEIL_' . (int)$m[2];
        }

        // Falls schon "TEIL_2" kommt
        if (preg_match('/^TEIL_0*([1-9]\d*)$/iu', $s, $m)) {
            return 'TEIL_' . (int)$m[1];
        }

        // Unknown => Default
        return 'STANDARD';
    }


    private function looksLikeNewsletter(string $pdfText): bool
    {
        $t = mb_strtolower($pdfText);
        $needles = ['newsletter', 'kw', 'kollektion', 'neuheiten', 'outfit'];
        $hits = 0;
        foreach ($needles as $n) {
            if (str_contains($t, $n)) { $hits++; }
        }
        return mb_strlen(trim($pdfText)) >= 200 && $hits >= 2;
    }

    private function mondayOfIsoWeek(int $year, int $week): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->setISODate($year, $week, 1)
            ->setTime(0, 0, 0);
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
        if ($p === '') { return null; } // gateway may route
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
        $w = [];
        $fn = $filename;

        if ($fn === '') { return []; }

        if (str_contains($fn, '..') || str_contains($fn, '/') || str_contains($fn, '\\')) {
            $w[] = 'Dateiname enthält Pfadzeichen. Bitte umbenennen (keine /, \\, ..).';
            return $w;
        }

        if (!preg_match('/^[\p{L}\p{N} äöüÄÖÜß_\-\.]+$/u', $fn)) {
            $w[] = 'Dateiname enthält Sonderzeichen, die Probleme verursachen können. Bitte umbenennen (nur Buchstaben/Zahlen/_/-).';
        }

        if (mb_strlen($fn) > 120) {
            $w[] = 'Dateiname ist sehr lang. Bitte ggf. kürzen.';
        }

        return $w;
    }


}
