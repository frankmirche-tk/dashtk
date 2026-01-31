<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Attribute\TrackUsage;
use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;
use App\Tracing\Trace;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Central chat orchestration service.
 *
 * Responsibilities
 * - Maintain chat session history (cache)
 * - Execute local resolvers first (ContactResolver, FormResolver)
 * - Execute KB/DB match for SOP + FORM (SupportSolutionRepository)
 * - Run AI only when appropriate (and/or as fallback)
 * - Provide "choices" for numeric selection UX (user replies "1", "2", "3")
 *
 * Newsletter-Create (Analyze/Patch/Confirm) wurde ausgelagert in NewsletterCreateResolver.
 */
final class SupportChatService
{
    private const SESSION_TTL_SECONDS = 3600; // 1h
    private const MAX_HISTORY_MESSAGES = 18;

    private const USAGE_KEY_ASK = 'support_chat.ask';

    private const CHOICES_TTL_SECONDS = 1800; // 30min
    private const MAX_CHOICES = 8;

    private readonly bool $isDev;

    public function __construct(
        private readonly AiChatGateway             $aiChat,
        private readonly SupportSolutionRepository $solutions,
        private readonly CacheInterface            $cache,
        private readonly LoggerInterface           $supportSolutionLogger,
        private readonly UsageTracker              $usageTracker,
        private readonly ContactResolver           $contactResolver,
        private readonly FormResolver              $formResolver,
        private readonly PromptTemplateLoader      $promptLoader,

        // Newsletter: Suche/Query (bestehender Resolver)
        private readonly NewsletterResolver        $newsletterResolver,

        // Newsletter: Create-Flow (NEU ausgelagert)
        private readonly NewsletterCreateResolver  $newsletterCreateResolver,

        // Document: Create-Flow (NEU ausgelagert)
        private readonly FormCreateResolver        $documentCreateResolver,

        KernelInterface                            $kernel,
    )
    {
        $this->isDev = $kernel->getEnvironment() === 'dev';
    }

    /**
     * Convenience trace wrapper to measure sub-operations.
     *
     * @param Trace|null $trace
     * @param string $name
     * @param callable():mixed $fn
     * @param array<string,mixed> $meta
     */
    private function span(?Trace $trace, string $name, callable $fn, array $meta = []): mixed
    {
        if ($trace) {
            return $trace->span($name, $fn, $meta);
        }
        return $fn();
    }

    #[TrackUsage(self::USAGE_KEY_ASK, weight: 5)]
    public function ask(
        string  $sessionId,
        string  $message,
        ?int    $dbOnlySolutionId = null,
        string  $provider = 'gemini',
        ?string $model = null,
        array   $context = [],
        ?Trace  $trace = null
    ): array
    {
        $sessionId = trim($sessionId);
        $message = trim($message);
        $provider = strtolower(trim($provider));

        // "mehr" -> Newsletter paging (nur vorbereitet, kein offset/limit implementiert)
        if (mb_strtolower($message) === 'mehr') {
            $key = 'support_chat.newsletter_paging.' . sha1($sessionId);
            $paging = $this->cache->get($key, fn(ItemInterface $i) => null);

            if (is_array($paging) && isset($paging['query'], $paging['offset'], $paging['pageSize'], $paging['from'], $paging['to'])) {
                return [
                    'answer' => "Paging ist vorbereitet, aber die Server-seitige Pagination (offset/limit) bauen wir als nächsten Schritt sauber ins Repository.\n"
                        . "Sag mir kurz: Sollen wir zuerst **Repository-Pagination** umsetzen oder direkt die **Newsletter-Import/Redaktionsmaske** finalisieren?",
                    'matches' => [],
                    'choices' => [],
                    'modeHint' => 'newsletter_paging_todo',
                ];
            }
        }

        $this->span($trace, 'usage.increment', function () {
            $this->usageTracker->increment(self::USAGE_KEY_ASK);
            return null;
        }, ['usage_key' => self::USAGE_KEY_ASK]);

        if ($sessionId === '') {
            $sessionId = $this->span($trace, 'session.fallback_id', fn() => $this->newSessionIdFallback());
        }

        // 0) Numeric selection ("1", "2", "3")
        $selection = $this->span($trace, 'choice.try_resolve', fn() => $this->resolveNumericSelection($sessionId, $message));
        if (is_array($selection)) {
            return $selection;
        }

        // A) DB-only (explicit SOP click from UI)
        if ($dbOnlySolutionId !== null) {
            $result = $this->span($trace, 'db_only.answer', fn() => $this->answerDbOnly($sessionId, $dbOnlySolutionId), [
                'solution_id' => $dbOnlySolutionId,
            ]);

            $this->supportSolutionLogger->info('db_only_response', [
                'sessionId' => $sessionId,
                'solutionId' => $dbOnlySolutionId,
                'stepsCount' => isset($result['steps']) && is_array($result['steps']) ? count($result['steps']) : 0,
            ]);

            return $result;
        }

        /**
         * B0) Local Contact Resolver (privacy: local_only, send_to_ai=false)
         */
        $contactHint = $this->span($trace, 'contact.intent.detect', function () use ($message) {
            $m = mb_strtolower($message);

            $keywords = [
                'kontakt', 'kontaktperson', 'ansprechpartner', 'telefon', 'tel', 'email', 'e-mail',
                'filiale', 'filialen', 'standort', 'adresse', 'anschrift', 'öffnungszeiten',
            ];

            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $m) ?: [];
            $tokens = array_values(array_filter($tokens, static fn($t) => $t !== ''));

            foreach ($keywords as $k) {
                if (in_array($k, $tokens, true)) {
                    return ['hit' => true, 'keyword' => $k];
                }
            }

            if (preg_match('/\b(tel|telefon|e-mail|email)\s*:/u', $m)) {
                return ['hit' => true, 'keyword' => 'contact_explicit'];
            }

            return ['hit' => false, 'keyword' => null];
        }, ['msg_len' => mb_strlen($message)]);

        $contactResult = $this->span($trace, 'contact.resolve', function () use ($message, $trace) {
            return $this->contactResolver->resolve($message, 5, $trace);
        }, [
            'policy' => 'local_only',
            'send_to_ai' => false,
            'source' => 'var/data/kontakt_*.json',
        ]);

        $hasContactMatches = is_array($contactResult) && !empty($contactResult['matches']);
        $intentHit = (bool)($contactHint['hit'] ?? false);

        if ($hasContactMatches) {
            $this->supportSolutionLogger->info('contact_resolved', [
                'sessionId' => $sessionId,
                'query' => $message,
                'intentHit' => $intentHit,
                'matchCount' => is_array($contactResult['matches'] ?? null) ? count($contactResult['matches']) : 0,
                'type' => $contactResult['type'] ?? null,
            ]);

            $payload = $this->span($trace, 'contact.answer.build', function () use ($contactResult) {
                $type = (string)($contactResult['type'] ?? 'none');
                $matches = $contactResult['matches'] ?? [];

                if (!is_array($matches) || $matches === []) {
                    return [
                        'answer' => "Ich habe lokal keine passenden Kontaktdaten gefunden. Bitte nenne mir einen Namen, eine FilialenNr (z.B. COSU) oder einen Standort.",
                        'choices' => [],
                    ];
                }

                $lines = [];
                $lines[] = $type === 'branch' ? "Gefundene Filiale(n):"
                    : ($type === 'person' ? "Gefundene Kontaktperson(en):" : "Treffer:");

                $choices = [];
                $i = 1;
                foreach ($matches as $m) {
                    $label = (string)($m['label'] ?? '');
                    if ($label === '') {
                        continue;
                    }
                    $lines[] = "{$i}) {$label}";
                    $choices[] = [
                        'kind' => 'contact',
                        'label' => $label,
                        'payload' => $m,
                    ];
                    $i++;
                    if ($i > self::MAX_CHOICES) {
                        break;
                    }
                }

                if ($choices !== []) {
                    $lines[] = "";
                    $lines[] = "Antworte mit **1–" . count($choices) . "**, um einen Eintrag zu öffnen.";
                }

                return [
                    'answer' => implode("\n", $lines),
                    'choices' => $choices,
                ];
            });

            if (!empty($payload['choices'])) {
                $this->storeChoices($sessionId, $payload['choices']);
            }

            return [
                'answer' => (string)($payload['answer'] ?? ''),
                'matches' => [],
                'modeHint' => 'contact_local',
                'contact' => $contactResult,
                'choices' => $payload['choices'] ?? [],
            ];
        }

        /**
         * NewsletterResolver (Suche/Query) mit Pending-State (Zeitraum First-Class)
         */
        $pendingKey = 'support_chat.newsletter_pending.' . sha1($sessionId);
        $pagingKey = 'support_chat.newsletter_paging.' . sha1($sessionId);

        // 1) Pending? (wir warten nur noch auf Zeitraum)
        $pending = $this->cache->get($pendingKey, static fn(ItemInterface $item) => null);

        if (is_array($pending) && ($pending['awaitingRange'] ?? false) === true) {
            $combined = trim(($pending['query'] ?? 'newsletter') . ' ' . $message);

            $nlPayload = $this->safeResolveNewsletter($sessionId, $combined);
            if (is_array($nlPayload)) {
                if (isset($nlPayload['newsletterPaging']) && is_array($nlPayload['newsletterPaging'])) {
                    $this->cache->delete($pagingKey);
                    $this->cache->get($pagingKey, function (ItemInterface $item) use ($nlPayload) {
                        $item->expiresAfter(1800);
                        return $nlPayload['newsletterPaging'];
                    });
                }

                if (($nlPayload['modeHint'] ?? '') !== 'newsletter_need_range') {
                    $this->cache->delete($pendingKey);
                }

                $this->supportSolutionLogger->info('newsletter_mode', [
                    'sessionId' => $sessionId,
                    'query' => $combined,
                    'rawMessage' => $message,
                    'mode' => (string)($nlPayload['modeHint'] ?? 'newsletter'),
                    'matchCount' => is_array($nlPayload['matches'] ?? null) ? count($nlPayload['matches']) : 0,
                    'matchIds' => is_array($nlPayload['matches'] ?? null)
                        ? array_map(static fn($m) => $m['id'] ?? null, $nlPayload['matches'])
                        : [],
                    'choices' => is_array($nlPayload['choices'] ?? null) ? count($nlPayload['choices']) : 0,
                ]);

                if (!empty($nlPayload['choices']) && is_array($nlPayload['choices'])) {
                    $this->storeChoices($sessionId, $nlPayload['choices']);
                }

                return $nlPayload;
            }

            $this->cache->delete($pendingKey);
        }

        // 2) Normaler Einstieg (User schreibt Newsletter-Intent)
        $nlPayload = $this->safeResolveNewsletter($sessionId, $message);
        if (is_array($nlPayload)) {
            if (isset($nlPayload['newsletterPaging']) && is_array($nlPayload['newsletterPaging'])) {
                $this->cache->delete($pagingKey);
                $this->cache->get($pagingKey, function (ItemInterface $item) use ($nlPayload) {
                    $item->expiresAfter(1800);
                    return $nlPayload['newsletterPaging'];
                });
            }

            if (($nlPayload['modeHint'] ?? '') === 'newsletter_need_range') {
                $this->cache->delete($pendingKey);
                $this->cache->get($pendingKey, function (ItemInterface $item) use ($message) {
                    $item->expiresAfter(1800);
                    return [
                        'awaitingRange' => true,
                        'query' => $message,
                    ];
                });
            }

            $this->supportSolutionLogger->info('newsletter_mode', [
                'sessionId' => $sessionId,
                'query' => $message,
                'mode' => (string)($nlPayload['modeHint'] ?? 'newsletter'),
                'matchCount' => is_array($nlPayload['matches'] ?? null) ? count($nlPayload['matches']) : 0,
                'matchIds' => is_array($nlPayload['matches'] ?? null)
                    ? array_map(static fn($m) => $m['id'] ?? null, $nlPayload['matches'])
                    : [],
                'choices' => is_array($nlPayload['choices'] ?? null) ? count($nlPayload['choices']) : 0,
            ]);

            if (!empty($nlPayload['choices']) && is_array($nlPayload['choices'])) {
                $this->storeChoices($sessionId, $nlPayload['choices']);
            }

            return $nlPayload;
        }

        /**
         * B1) KB match (DB) – yields SOP and FORM.
         */
        $matches = $this->span($trace, 'kb.match', fn() => $this->findMatches($message), [
            'query_len' => mb_strlen($message),
        ]);

        $matches = $this->dedupeMatchesById($matches);

        $forms = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) === 'FORM'));
        $sops = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));

        $sops = $this->filterSopsDuplicatingFormTitles($sops, $forms);

        $formChoices = [];
        if ($forms !== []) {
            $i = 1;
            foreach ($forms as $f) {
                $title = (string)($f['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $formChoices[] = [
                    'kind' => 'form',
                    'label' => $title,
                    'payload' => $f,
                ];
                $i++;
                if ($i > self::MAX_CHOICES) {
                    break;
                }
            }
        }

        $hasFormKeywords = $this->span($trace, 'form.keyword.detect', fn() => $this->formResolver->hasFormKeywords($message), [
            'query_len' => mb_strlen($message),
        ]);

        if ($hasFormKeywords) {
            $this->supportSolutionLogger->info('form_keyword_mode', [
                'sessionId' => $sessionId,
                'query' => $message,
                'forms' => count($forms),
                'sops' => count($sops),
                'mode' => ($formChoices !== []) ? 'form_kw_db' : 'form_kw_empty',
            ]);

            if ($formChoices !== []) {
                $this->storeChoices($sessionId, $formChoices);

                $lines = [];
                $lines[] = "Ich habe passende **Formulare** gefunden:";
                $n = 1;
                foreach ($formChoices as $c) {
                    $updated = (string)($c['payload']['updatedAt'] ?? '');
                    $symptoms = trim((string)($c['payload']['symptoms'] ?? ''));

                    $line = "{$n}) {$c['label']}" . ($updated !== '' ? " (zuletzt aktualisiert: {$updated})" : '');
                    if ($symptoms !== '') {
                        $line .= "\n   ↳ " . $symptoms;
                    }

                    $lines[] = $line;
                    $n++;
                }
                $lines[] = "";
                $lines[] = "Antworte mit **1–" . count($formChoices) . "**, um ein Formular zu öffnen.";

                return [
                    'answer' => implode("\n", $lines),
                    'matches' => $sops,
                    'choices' => $formChoices,
                    'modeHint' => 'form_kw_db',
                ];
            }

            return [
                'answer' => "Ich habe kein passendes Formular gefunden. Bitte prüfe die Schreibweise oder ergänze 1–2 Stichwörter (z.B. „Etikettendruck Formular“).",
                'matches' => $sops,
                'choices' => [],
                'modeHint' => 'form_kw_empty',
            ];
        }

        // Without form keywords: show form choices (UI) but still allow AI
        if ($formChoices !== []) {
            $this->storeChoices($sessionId, $formChoices);
        }

        /**
         * C) AI + DB (SOP guidance)
         */
        $history = $this->span($trace, 'cache.history_load', fn() => $this->loadHistory($sessionId), [
            'session_hash' => sha1($sessionId),
        ]);

        $history = $this->span($trace, 'history.ensure_system_prompt', function () use ($history) {
            $tpl = $this->promptLoader->load('KiChatBotPrompt.config');

            // Optional: Datum rendern (damit Gemini Zeiträume korrekt interpretiert)
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
            $expectedSystem = $this->promptLoader->render($tpl['system'], ['today' => $today]);

            // Marker zur Prompt-Versionierung (in SYSTEM einfügen, z.B. in TKFashionPolicyPrompt.config)
            $marker = 'PROMPT_ID: dashTk_assist_v2';

            // 1) System-Message finden
            $systemIndex = null;
            foreach ($history as $i => $msg) {
                if (($msg['role'] ?? null) === 'system') {
                    $systemIndex = $i;
                    break;
                }
            }

            // 2) Wenn keine System-Message existiert: vorne einfügen
            if ($systemIndex === null) {
                array_unshift($history, ['role' => 'system', 'content' => $expectedSystem]);
                return $history;
            }

            // 3) Wenn alte/andere System-Message: ersetzen
            $current = (string)($history[$systemIndex]['content'] ?? '');
            if ($marker !== '' && stripos($current, $marker) === false) {
                $history[$systemIndex]['content'] = $expectedSystem;
            }

            // 4) Sicherstellen: System steht ganz vorne
            if ($systemIndex !== 0) {
                $sys = $history[$systemIndex];
                unset($history[$systemIndex]);
                array_unshift($history, $sys);
                $history = array_values($history);
            }

            return $history;
        });


        $history[] = ['role' => 'user', 'content' => $message];

        $kbContext = $this->span($trace, 'kb.build_context', fn() => $this->buildKbContext($matches), [
            'match_count' => count($matches),
        ]);

        // Wenn Form-Choices aus UI vorhanden sind, dem Modell explizit mitgeben
        if ($formChoices !== []) {
            $kbContext .= "\nFORM_CHOICES_UI:\n";
            foreach ($formChoices as $c) {
                $label = (string)($c['label'] ?? '');
                $symptoms = trim((string)($c['payload']['symptoms'] ?? ''));
                $kbContext .= "- {$label}" . ($symptoms !== '' ? (" | " . $symptoms) : "") . "\n";
            }
            $kbContext .= "ANWEISUNG: Wenn FORM_CHOICES_UI nicht leer ist, behaupte niemals \"kein Zugriff\" und biete Auswahl per Nummer an.\n";
        }


        $context = $this->span($trace, 'ai.context_defaults', function () use ($context) {
            $context['usage_key'] ??= self::USAGE_KEY_ASK;
            $context['cache_hit'] ??= false;
            return $context;
        }, ['usage_key' => $context['usage_key'] ?? self::USAGE_KEY_ASK]);

        $model = $this->span($trace, 'ai.model_resolve', function () use ($provider, $model) {
            if ($model === null || trim((string)$model) === '') {
                if ($provider === 'openai') {
                    $model = (string)($_ENV['OPENAI_DEFAULT_MODEL'] ?? $_SERVER['OPENAI_DEFAULT_MODEL'] ?? '');
                } elseif ($provider === 'gemini') {
                    $model = (string)($_ENV['GEMINI_DEFAULT_MODEL'] ?? $_SERVER['GEMINI_DEFAULT_MODEL'] ?? '');
                }
                $model = trim((string)$model);
                $model = $model !== '' ? $model : null;
            }
            return $model;
        }, ['provider' => $provider]);

        $trimmedHistory = $this->span($trace, 'history.trim', fn() => $this->trimHistory($history), [
            'history_count_in' => count($history),
            'max' => self::MAX_HISTORY_MESSAGES,
        ]);


        // DEV/Debug: prüfen, ob system prompt wirklich vorne steht (nur wenn isDev aktiv)
        if ($this->isDev) {
            $systemCount = 0;
            $firstRole = $trimmedHistory[0]['role'] ?? null;
            foreach ($trimmedHistory as $msg) {
                if (($msg['role'] ?? null) === 'system') {
                    $systemCount++;
                }
            }
            $this->supportSolutionLogger->debug('prompt_debug', [
                'firstRole' => $firstRole,
                'systemCount' => $systemCount,
                'firstSystemPreview' => ($firstRole === 'system')
                    ? mb_substr((string)($trimmedHistory[0]['content'] ?? ''), 0, 180)
                    : null,
            ]);
        }

        $this->supportSolutionLogger->info('chat_request', [
            'sessionId' => $sessionId,
            'message' => $message,
            'matchCount' => count($matches),
            'matchIds' => array_map(static fn($m) => $m['id'] ?? null, $matches),
            'kbContextChars' => strlen($kbContext),
            'provider' => $provider,
            'model' => $model,
            'usageKey' => $context['usage_key'] ?? self::USAGE_KEY_ASK,
        ]);

        try {
            $answer = $this->span($trace, 'ai.call', function () use ($trimmedHistory, $kbContext, $provider, $model, $context) {
                return $this->aiChat->chat(
                    history: $trimmedHistory,
                    kbContext: $kbContext,
                    provider: $provider,
                    model: $model,
                    context: $context
                );
            }, [
                'provider' => $provider,
                'model' => $model,
                'history_msgs' => count($trimmedHistory),
                'kb_chars' => strlen($kbContext),
            ]);
        } catch (\Throwable $e) {
            $this->supportSolutionLogger->error('chat_execute_failed', [
                'sessionId' => $sessionId,
                'message' => $message,
                'error' => $e->getMessage(),
                'class' => $e::class,
                'provider' => $provider,
                'model' => $model,
            ]);

            $msg = $this->isDev
                ? ('Fehler beim Erzeugen der Antwort (DEV): ' . $e->getMessage())
                : 'Fehler beim Erzeugen der Antwort. Bitte später erneut versuchen.';

            return [
                'answer' => $msg,
                'matches' => $sops,
                'choices' => $formChoices,
                'modeHint' => 'ai_with_db',
            ];
        }

        // save history
        $history[] = ['role' => 'assistant', 'content' => $answer];
        $this->span($trace, 'cache.history_save', function () use ($sessionId, $history) {
            $this->saveHistory($sessionId, $history);
            return null;
        });

        $this->supportSolutionLogger->info('chat_response', [
            'sessionId' => $sessionId,
            'answerChars' => strlen($answer),
            'provider' => $provider,
            'model' => $model,
        ]);

        // store numeric-selection choices: forms + sops
        $kbChoices = $this->buildKbChoices($matches);

        $storedChoices = [];
        if ($formChoices !== []) {
            $storedChoices = array_merge($storedChoices, $formChoices);
        }
        if ($kbChoices !== []) {
            $storedChoices = array_merge($storedChoices, $kbChoices);
        }
        if ($storedChoices !== []) {
            $this->storeChoices($sessionId, $storedChoices);
        }

        return [
            'answer' => $answer,
            'matches' => $sops,
            'modeHint' => 'ai_with_db',
            'choices' => $formChoices, // only forms in UI
        ];
    }

    // ---------------------------------------------------------------------
    // Newsletter Create (delegiert)
    // ---------------------------------------------------------------------

    /**
     * POST /api/chat/newsletter/analyze
     * => kompletter Create-Flow ist im NewsletterCreateResolver.
     */
    public function newsletterAnalyze(
        string        $sessionId,
        string        $message,
        string        $driveUrl,
        ?UploadedFile $file,
        string        $provider,
        ?string       $model,
        ?Trace        $trace = null
    ): array
    {
        return $this->newsletterCreateResolver->analyze(
            sessionId: $sessionId,
            message: $message,
            driveUrl: $driveUrl,
            file: $file,
            model: $model,
            trace: $trace
        );
    }

    /**
     * POST /api/chat/newsletter/patch
     */
    public function newsletterPatch(
        string  $sessionId,
        string  $draftId,
        string  $message,
        string  $provider,
        ?string $model,
        ?Trace  $trace = null
    ): array
    {
        return $this->newsletterCreateResolver->patch(
            sessionId: $sessionId,
            draftId: $draftId,
            message: $message
        );
    }

    /**
     * POST /api/chat/newsletter/confirm
     */
    public function newsletterConfirm(
        string $sessionId,
        string $draftId,
        ?Trace $trace = null
    ): array
    {
        return $this->newsletterCreateResolver->confirm(
            sessionId: $sessionId,
            draftId: $draftId
        );
    }

    // ---------------------------------------------------------------------
    // DB-only SOP
    // ---------------------------------------------------------------------

    private function answerDbOnly(string $sessionId, int $solutionId): array
    {
        $solution = $this->solutions->find($solutionId);

        if (!$solution instanceof SupportSolution) {
            return [
                'answer' => 'Die ausgewählte SOP wurde nicht gefunden.',
                'matches' => [],
                'modeHint' => 'db_only',
                'tts' => 'Die SOP wurde nicht gefunden.',
                'mediaUrl' => '',
                'steps' => [],
            ];
        }

        $stepsEntities = $solution->getSteps()->toArray();
        usort($stepsEntities, static fn($a, $b) => $a->getStepNo() <=> $b->getStepNo());

        $stepsPayload = [];
        foreach ($stepsEntities as $st) {
            $stepsPayload[] = [
                'id' => $st->getId(),
                'stepNo' => (int)$st->getStepNo(),
                'instruction' => (string)$st->getInstruction(),
                'expectedResult' => $st->getExpectedResult(),
                'nextIfFailed' => $st->getNextIfFailed(),
                'mediaPath' => $st->getMediaPath(),
                'mediaUrl' => method_exists($st, 'getMediaUrl') ? $st->getMediaUrl() : null,
                'mediaMimeType' => $st->getMediaMimeType(),
            ];
        }

        $lines = [];
        $lines[] = "SOP: {$solution->getTitle()}";
        if ($solution->getSymptoms()) {
            $lines[] = "Symptome: {$solution->getSymptoms()}";
        }
        $lines[] = "";

        if ($stepsEntities) {
            foreach ($stepsEntities as $st) {
                $lines[] = $st->getStepNo() . ') ' . $st->getInstruction();
            }
        } else {
            $lines[] = 'Keine Steps hinterlegt.';
        }

        return [
            'answer' => implode("\n", $lines),
            'matches' => [],
            'modeHint' => 'db_only',
            'tts' => 'Hallo, ich zeige dir jetzt wie du die Aufträge löschst.',
            'mediaUrl' => '/guides/print/step1.gif',
            'steps' => $stepsPayload,
        ];
    }

    // ---------------------------------------------------------------------
    // KB match / mapping
    // ---------------------------------------------------------------------

    private function findMatches(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return [];
        }

        $raw = $this->solutions->findBestMatches($message, 5);

        $mapped = [];
        foreach ($raw as $m) {
            $s = $m['solution'] ?? null;
            if (!$s instanceof SupportSolution) {
                continue;
            }
            $mapped[] = $this->mapMatch($s, (int)($m['score'] ?? 0));
        }

        $this->supportSolutionLogger->debug('db_matches', [
            'message' => $message,
            'matchCount' => count($mapped),
            'matchIds' => array_map(static fn($x) => $x['id'] ?? null, $mapped),
        ]);

        return $mapped;
    }

    private function mapMatch(SupportSolution $solution, int $score): array
    {
        $id = (int)$solution->getId();
        $iri = '/api/support_solutions/' . $id;

        $type = $solution->getType();
        $updatedAt = $solution->getUpdatedAt()->format('Y-m-d H:i');

        $base = [
            'id' => $id,
            'title' => (string)$solution->getTitle(),
            'score' => $score,
            'url' => $iri,
            'type' => $type,
            'updatedAt' => $updatedAt,
            'symptoms' => (string)($solution->getSymptoms() ?? ''),
            'category' => (string)($solution->getCategory() ?? ''), // ✅ NEU
            // Newsletter-Metadaten (für Trefferliste "seit Datum")
            'newsletterYear' => method_exists($solution, 'getNewsletterYear') ? $solution->getNewsletterYear() : null,
            'newsletterKw' => method_exists($solution, 'getNewsletterKw') ? $solution->getNewsletterKw() : null,
            'newsletterEdition' => method_exists($solution, 'getNewsletterEdition') ? $solution->getNewsletterEdition() : null,
            'publishedAt' => method_exists($solution, 'getPublishedAt') && $solution->getPublishedAt()
                ? $solution->getPublishedAt()->format('Y-m-d')
                : null,
        ];

        if ($type === 'FORM') {
            return $base + [
                    'mediaType' => $solution->getMediaType(),
                    'externalMediaProvider' => $solution->getExternalMediaProvider(),
                    'externalMediaUrl' => $solution->getExternalMediaUrl(),
                    'externalMediaId' => $solution->getExternalMediaId(),
                ];
        }

        $stepsUrl = '/api/support_solution_steps?solution=' . rawurlencode($iri);
        return $base + [
                'stepsUrl' => $stepsUrl,
            ];
    }



    private function buildKbContext(array $matches): string
    {

        if ($matches === []) {
            return "KB_CONTEXT: none\n";
        }

        // Alles reinnehmen (SOP/Newsletter/Form), damit KI keine "kein Zugriff" Behauptungen macht
        $items = array_values($matches);
        if ($items === []) {
            return "KB_CONTEXT: none\n";
        }

        // Newsletter erkennen: über newsletterYear/publishedAt oder Kategorie/Title
        $newsletters = [];
        $forms = [];
        $others = [];
        foreach ($items as $m) {
            if (($m['type'] ?? null) === 'FORM') {
                $forms[] = $m;
                continue;
            }
            $cat = mb_strtolower((string)($m['category'] ?? ''));
            $ttl = mb_strtolower((string)($m['title'] ?? ''));
            $isNl = !empty($m['newsletterYear'])
                || !empty($m['publishedAt'])
                || str_contains($cat, 'newsletter')
                || str_contains($ttl, 'newsletter');

            if ($isNl) {
                $newsletters[] = $m;
            } else {
                $others[] = $m;
            }
        }

        $lines = [];
        $lines[] = "KB_CONTEXT: present";
        $lines[] = "WICHTIG: Nutze die folgenden Treffer als primäre Quelle. Behaupte NICHT, dass du keinen Zugriff/kein Archiv hast, wenn Treffer gelistet sind.";
        $lines[] = "";

        // 1) Newsletter-Block (genau das, was ihr wollt)
        $lines[] = "NEWSLETTER_MATCHES:";
        if ($newsletters === []) {
            $lines[] = "- (none)";
        } else {
            foreach ($newsletters as $hit) {
                $id = (int)($hit['id'] ?? 0);
                $title = trim((string)($hit['title'] ?? ''));
                $kw = (string)($hit['newsletterKw'] ?? '');
                $year = (string)($hit['newsletterYear'] ?? '');
                $edition = (string)($hit['newsletterEdition'] ?? '');
                $published = (string)($hit['publishedAt'] ?? '');
                $symptoms = trim((string)($hit['symptoms'] ?? ''));

                $meta = [];
                if ($published !== '') { $meta[] = "published_at={$published}"; }
                if ($year !== '' || $kw !== '' || $edition !== '') {
                    $meta[] = "newsletter={$year}-KW{$kw}" . ($edition !== '' ? "-{$edition}" : '');
                }
                $metaStr = $meta ? (" [" . implode(", ", $meta) . "]") : "";

                $lines[] = "- (#{$id}) {$title}{$metaStr}";
                if ($symptoms !== '') {
                    $lines[] = "  excerpt: " . $symptoms;
                }
            }
        }

        $lines[] = "";
        $lines[] = "FORM_MATCHES:";
        if ($forms === []) {
            $lines[] = "- (none)";
        } else {
            foreach ($forms as $hit) {
                $id = (int)($hit['id'] ?? 0);
                $title = trim((string)($hit['title'] ?? ''));
                $symptoms = trim((string)($hit['symptoms'] ?? ''));
                $lines[] = "- (#{$id}) {$title}";
                if ($symptoms !== '') {
                    $lines[] = "  excerpt: " . $symptoms;
                }
            }
        }

        $lines[] = "";
        $lines[] = "OTHER_KB_MATCHES:";
        if ($others === []) {
            $lines[] = "- (none)";
        } else {
            foreach ($others as $hit) {
                $lines[] = sprintf(
                    '- (#%d) %s (Score %d) IRI: %s',
                    (int)($hit['id'] ?? 0),
                    (string)($hit['title'] ?? ''),
                    (int)($hit['score'] ?? 0),
                    (string)($hit['url'] ?? '')
                );
            }
        }

        $lines[] = "";
        $lines[] = "ANWEISUNG:";
        $lines[] = "- Wenn der Nutzer nach Newsletter 'seit DD.MM.YYYY' fragt: liste NEWSLETTER_MATCHES.";
        $lines[] = "- Wenn NEWSLETTER_MATCHES = (none): antworte exakt: \"Keine passenden Newsletter gefunden.\"";
        $lines[] = "- Wenn der Nutzer nach Formularen fragt: liste FORM_MATCHES oder biete Auswahl/Nummern an, falls vorhanden.";
        $lines[] = "- Behaupte niemals \"kein Zugriff\" wenn irgendeine *_MATCHES Liste nicht leer ist.";

        return implode("\n", $lines) . "\n";
    }



    // ---------------------------------------------------------------------
    // History / choices cache
    // ---------------------------------------------------------------------

    private function loadHistory(string $sessionId): array
    {
        $key = $this->historyCacheKey($sessionId);

        $val = $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(self::SESSION_TTL_SECONDS);
            return [];
        });

        return is_array($val) ? $val : [];
    }

    private function saveHistory(string $sessionId, array $history): void
    {
        $key = $this->historyCacheKey($sessionId);

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($history) {
            $item->expiresAfter(self::SESSION_TTL_SECONDS);
            return $history;
        });
    }

    private function trimHistory(array $history): array
    {
        $system = [];
        $rest = $history;

        if ($history !== [] && ($history[0]['role'] ?? null) === 'system') {
            $system = [$history[0]];
            $rest = array_slice($history, 1);
        }

        $rest = array_slice($rest, -(self::MAX_HISTORY_MESSAGES - count($system)));
        return array_merge($system, $rest);
    }

    private function historyCacheKey(string $sessionId): string
    {
        return 'support_chat.history.' . sha1($sessionId);
    }

    private function choicesCacheKey(string $sessionId): string
    {
        return 'support_chat.choices.' . sha1($sessionId);
    }

    private function storeChoices(string $sessionId, array $choices): void
    {
        $key = $this->choicesCacheKey($sessionId);

        $choices = array_values(array_slice($choices, 0, self::MAX_CHOICES));

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($choices) {
            $item->expiresAfter(self::CHOICES_TTL_SECONDS);
            return $choices;
        });
    }

    private function loadChoices(string $sessionId): array
    {
        $key = $this->choicesCacheKey($sessionId);

        $val = $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(self::CHOICES_TTL_SECONDS);
            return [];
        });

        return is_array($val) ? $val : [];
    }

    // ---------------------------------------------------------------------
    // Numeric selection
    // ---------------------------------------------------------------------

    private function resolveNumericSelection(string $sessionId, string $message): ?array
    {
        $m = trim($message);
        if ($m === '' || !preg_match('/^\d+$/', $m)) {
            return null;
        }

        $idx = (int)$m;
        if ($idx <= 0) {
            return null;
        }

        $choices = $this->loadChoices($sessionId);
        if ($choices === []) {
            return [
                'answer' => "Ich habe keine Auswahl mehr gespeichert. Bitte formuliere die Anfrage erneut (z.B. „Formular Reisekosten“).",
                'matches' => [],
                'modeHint' => 'choice_empty',
            ];
        }

        $choice = $choices[$idx - 1] ?? null;
        if (!is_array($choice)) {
            return [
                'answer' => "Bitte wähle eine Zahl zwischen 1 und " . count($choices) . ".",
                'matches' => [],
                'modeHint' => 'choice_out_of_range',
                'choices' => $choices,
            ];
        }

        $kind = (string)($choice['kind'] ?? '');
        $label = (string)($choice['label'] ?? '');
        $payload = is_array($choice['payload'] ?? null) ? $choice['payload'] : [];

        if ($kind === 'form') {
            $updated = (string)($payload['updatedAt'] ?? '');

            $provider = $payload['externalMediaProvider'] ?? null;
            $externalUrl = $payload['externalMediaUrl'] ?? null;
            $externalId = $payload['externalMediaId'] ?? null;
            $symptoms = trim((string)($payload['symptoms'] ?? ''));

            $previewUrl = $this->formResolver->buildPreviewUrl(
                is_string($provider) ? $provider : null,
                is_string($externalUrl) ? $externalUrl : null,
                is_string($externalId) ? $externalId : null
            );

            $fallbackUrl = (string)($payload['url'] ?? '');
            $urlForText = $previewUrl ?: $fallbackUrl;

            $lines = [];
            $lines[] = "✅ **Formular geöffnet:** {$label}";
            if ($updated !== '') {
                $lines[] = "Zuletzt aktualisiert: {$updated}";
            }
            if ($symptoms !== '') {
                $lines[] = "Hinweis: {$symptoms}";
            }

            if ($urlForText !== '') {
                $lines[] = "Link: {$urlForText}";
            } else {
                $lines[] = "Link: (keine Vorschau-URL verfügbar – bitte Formular-Eintrag prüfen)";
            }

            $lines[] = "";
            $lines[] = "Möchtest du ein anderes Formular aus der Liste öffnen (z.B. „2“) oder suchst du etwas anderes?";

            return [
                'answer' => implode("\n", $lines),
                'matches' => [],
                'choices' => [],
                'modeHint' => 'choice_form',
                'selected' => $choice,
                'formCard' => [
                    'title' => $label,
                    'updatedAt' => $updated,
                    'url' => $previewUrl ?: $fallbackUrl,
                    'provider' => (string)($provider ?? ''),
                    'symptoms' => $symptoms,
                ],
            ];
        }

        if ($kind === 'contact') {
            $lines = [];
            $lines[] = "Kontakt: {$label}";
            foreach (['phone' => 'Telefon', 'email' => 'E-Mail', 'address' => 'Adresse'] as $k => $title) {
                if (!empty($payload[$k])) {
                    $lines[] = "{$title}: " . (string)$payload[$k];
                }
            }
            $lines[] = "";
            $lines[] = "Soll ich noch etwas anderes nachschlagen (z.B. eine andere Filiale oder Person)?";

            return [
                'answer' => implode("\n", $lines),
                'matches' => [],
                'modeHint' => 'choice_contact',
                'selected' => $choice,
            ];
        }

        if ($kind === 'sop') {
            $id = (int)($payload['id'] ?? 0);
            if ($id > 0) {
                return $this->answerDbOnly($sessionId, $id);
            }
        }

        return [
            'answer' => "Ich konnte diese Auswahl nicht auflösen. Bitte formuliere die Anfrage erneut.",
            'matches' => [],
            'modeHint' => 'choice_unknown',
        ];
    }

    private function buildKbChoices(array $matches): array
    {
        $sops = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));
        $choices = [];
        foreach ($sops as $s) {
            $id = (int)($s['id'] ?? 0);
            $title = (string)($s['title'] ?? '');
            if ($id <= 0 || $title === '') {
                continue;
            }
            $choices[] = [
                'kind' => 'sop',
                'label' => $title,
                'payload' => ['id' => $id],
            ];
        }
        return array_values(array_slice($choices, 0, self::MAX_CHOICES));
    }

    private function newSessionIdFallback(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function dedupeMatchesById(array $matches): array
    {
        $seen = [];
        $out = [];

        foreach ($matches as $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $m;
        }

        return $out;
    }

    private function filterSopsDuplicatingFormTitles(array $sops, array $forms): array
    {
        if ($forms === [] || $sops === []) {
            return $sops;
        }

        $formTitles = [];
        foreach ($forms as $f) {
            $t = $this->normalizeTitle((string)($f['title'] ?? ''));
            if ($t !== '') {
                $formTitles[$t] = true;
            }
        }

        return array_values(array_filter($sops, function (array $s) use ($formTitles) {
            $t = $this->normalizeTitle((string)($s['title'] ?? ''));
            return $t === '' ? true : !isset($formTitles[$t]);
        }));
    }

    private function normalizeTitle(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return $t;
    }

    private function safeResolveNewsletter(string $sessionId, string $query): ?array
    {
        try {
            return $this->newsletterResolver->resolve($query);
        } catch (\Throwable $e) {
            $this->supportSolutionLogger->error('newsletter_execute_failed', [
                'sessionId' => $sessionId,
                'query' => $query,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return [
                'answer' => $this->isDev
                    ? ('Newsletter-Resolver Fehler (DEV): ' . $e->getMessage())
                    : 'Newsletter-Suche ist gerade fehlgeschlagen. Bitte dev.log prüfen.',
                'matches' => [],
                'choices' => [],
                'modeHint' => 'newsletter_failed',
            ];
        }
    }

    // ---------------------------------------------------------------------
// Document Create (delegiert)
// ---------------------------------------------------------------------

    /**
     * POST /api/chat/document/analyze
     * => kompletter Create-Flow ist im FormCreateResolver.
     */
    public function documentAnalyze(
        string        $sessionId,
        string        $message,
        string        $driveUrl,
        ?UploadedFile $file,
        string        $provider,
        ?string       $model,
        ?Trace        $trace = null
    ): array
    {
        return $this->documentCreateResolver->analyze(
            sessionId: $sessionId,
            message: $message,
            driveUrl: $driveUrl,
            file: $file,
            model: $model,
            trace: $trace
        );
    }

    /**
     * POST /api/chat/document/patch
     */
    public function documentPatch(
        string  $sessionId,
        string  $draftId,
        string  $message,
        string  $provider,
        ?string $model,
        ?Trace  $trace = null
    ): array
    {
        return $this->documentCreateResolver->patch(
            sessionId: $sessionId,
            draftId: $draftId,
            message: $message
        );
    }

    /**
     * POST /api/chat/document/confirm
     */
    public function documentConfirm(
        string $sessionId,
        string $draftId,
        ?Trace $trace = null
    ): array
    {
        return $this->documentCreateResolver->confirm(
            sessionId: $sessionId,
            draftId: $draftId
        );
    }

}
