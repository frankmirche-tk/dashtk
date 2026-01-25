<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Attribute\TrackUsage;
use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;
use App\Tracing\Trace;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Central chat orchestration service.
 *
 * Responsibilities:
 * - Maintain chat session history (cache)
 * - Execute local resolvers first (ContactResolver, FormResolver)
 * - Execute KB/DB match for SOP + FORM (SupportSolutionRepository)
 * - Run AI only when appropriate (and/or as fallback)
 * - Provide "choices" for numeric selection UX (user replies "1", "2", "3")
 */
final class SupportChatService
{
    private const SESSION_TTL_SECONDS = 3600; // 1h
    private const MAX_HISTORY_MESSAGES = 18;
    private const USAGE_KEY_ASK = 'support_chat.ask';

    /**
     * Choice list (user can answer "1", "2", "3" in the same single input line).
     */
    private const CHOICES_TTL_SECONDS = 1800; // 30min
    private const MAX_CHOICES = 8;

    private readonly bool $isDev;

    public function __construct(
        private readonly AiChatGateway $aiChat,
        private readonly SupportSolutionRepository $solutions,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $supportSolutionLogger,
        private readonly UsageTracker $usageTracker,
        private readonly ContactResolver $contactResolver,
        private readonly FormResolver $formResolver,
        KernelInterface $kernel,
    ) {
        $this->isDev = $kernel->getEnvironment() === 'dev';
    }

    /**
     * Convenience trace wrapper to measure sub-operations.
     *
     * @param Trace|null $trace Trace instance (optional)
     * @param string $name Span name
     * @param callable():mixed $fn Work function
     * @param array<string,mixed> $meta Span metadata
     * @return mixed
     */
    private function span(?Trace $trace, string $name, callable $fn, array $meta = []): mixed
    {
        if ($trace) {
            return $trace->span($name, $fn, $meta);
        }
        return $fn();
    }

    /**
     * Main chat entrypoint.
     *
     * Flow priority:
     * 1) Numeric selection resolution (choices cached)
     * 2) DB-only answer (explicit SOP click)
     * 3) ContactResolver (local-only)
     * 4) KB match (DB): yields SOP and FORM
     *    - If user typed form-keywords (pdf/form/formular/...), then always respond DB-only (no AI)
     *    - Else: always expose FORM as choices (like SOP suggestions) while still allowing AI
     * 5) AI call with KB context as guidance (SOPs only)
     *
     * @param string $sessionId Session identifier (client provided)
     * @param string $message User message
     * @param int|null $dbOnlySolutionId If set, returns the SOP steps directly (no AI)
     * @param string $provider AI provider name (e.g. gemini/openai)
     * @param string|null $model Optional model override
     * @param array<string,mixed> $context Optional AI context
     * @param Trace|null $trace Optional trace instance
     *
     * @return array<string,mixed> Response payload for the frontend (answer, matches, choices, steps, etc.)
     */
    #[TrackUsage(self::USAGE_KEY_ASK, weight: 5)]
    public function ask(
        string $sessionId,
        string $message,
        ?int $dbOnlySolutionId = null,
        string $provider = 'gemini',
        ?string $model = null,
        array $context = [],
        ?Trace $trace = null
    ): array {
        $sessionId = trim($sessionId);
        $message   = trim($message);
        $provider  = strtolower(trim($provider));

        $this->span($trace, 'usage.increment', function () {
            $this->usageTracker->increment(self::USAGE_KEY_ASK);
            return null;
        }, ['usage_key' => self::USAGE_KEY_ASK]);

        if ($sessionId === '') {
            $sessionId = $this->span($trace, 'session.fallback_id', function () {
                return $this->newSessionIdFallback();
            });
        }

        /**
         * BRANCH 0: numeric selection ("1", "2", "3") from last results
         * (keeps the single input line UX).
         */
        $selection = $this->span($trace, 'choice.try_resolve', function () use ($sessionId, $message) {
            return $this->resolveNumericSelection($sessionId, $message);
        });

        if (is_array($selection)) {
            return $selection;
        }

        /**
         * BRANCH A: DB-only (explicit click from UI, existing flow)
         */
        if ($dbOnlySolutionId !== null) {
            return $this->span($trace, 'db_only.answer', function () use ($sessionId, $dbOnlySolutionId) {
                return $this->answerDbOnly($sessionId, $dbOnlySolutionId);
            }, [
                'solution_id' => $dbOnlySolutionId,
            ]);
        }

        /**
         * BRANCH B0: Local Contact Resolver (privacy: local_only, send_to_ai=false)
         */
        $contactHint = $this->span($trace, 'contact.intent.detect', function () use ($message) {
            $m = mb_strtolower($message);

            $keywords = [
                'kontakt', 'kontaktperson', 'ansprechpartner', 'telefon', 'tel', 'email', 'e-mail',
                'filiale', 'filialen', 'standort', 'adresse', 'anschrift', 'öffnungszeiten',
            ];

            foreach ($keywords as $k) {
                if (str_contains($m, $k)) {
                    return ['hit' => true, 'keyword' => $k];
                }
            }

            // Allow very short tokens (e.g. branch code)
            $len = mb_strlen(trim($m));
            return ['hit' => ($len > 0 && $len <= 8), 'keyword' => null];
        }, [
            'msg_len' => mb_strlen($message),
        ]);

        $contactResult = $this->span($trace, 'contact.resolve', function () use ($message, $trace) {
            return $this->contactResolver->resolve($message, 5, $trace);
        }, [
            'policy' => 'local_only',
            'send_to_ai' => false,
            'source' => 'var/data/kontakt_*.json',
        ]);

        $hasContactMatches = is_array($contactResult) && !empty($contactResult['matches']);
        $intentHit = (bool)($contactHint['hit'] ?? false);

        if ($intentHit || $hasContactMatches) {
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

                if ($this->isDev) {
                    $lines[] = "";
                    $lines[] = "DEV-Hinweis: Kontakt wurde lokal gelöst (nicht an AI gesendet).";
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
                'answer' => $payload['answer'] ?? '',
                'matches' => [],
                'modeHint' => 'contact_local',
                'contact' => $contactResult,
                'choices' => $payload['choices'] ?? [],
            ];
        }

        /**
         * BRANCH B1: KB match (DB) – can yield SOP and FORM.
         */
        $matches = $this->span($trace, 'kb.match', function () use ($message) {
            return $this->findMatches($message);
        }, [
            'query_len' => mb_strlen($message),
        ]);

        $matches = $this->dedupeMatchesById($matches);

        // Split for downstream usage
        $forms = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) === 'FORM'));
        $sops  = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));

        $sops = $this->filterSopsDuplicatingFormTitles($sops, $forms); // ✅ neu


        // Build FORM choices whenever FORM matches exist (like SOP suggestions)
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

        /**
         * RULE: AI is NOT used when the search explicitly contains form-keywords.
         * (pdf, form, formular, dokument, antrag, vorlage)
         *
         * In that case we answer purely DB-driven (forms as choices, plus SOPs as matches).
         */
        $hasFormKeywords = $this->span($trace, 'form.keyword.detect', function () use ($message) {
            return $this->formResolver->hasFormKeywords($message);
        }, [
            'query_len' => mb_strlen($message),
        ]);

        if ($hasFormKeywords) {
            if ($formChoices !== []) {
                $this->storeChoices($sessionId, $formChoices);

                $lines = [];
                $lines[] = "Ich habe passende **Formulare** gefunden:";
                $n = 1;
                foreach ($formChoices as $c) {
                    $updated = (string)($c['payload']['updatedAt'] ?? '');
                    $symptoms = trim((string)($c['payload']['symptoms'] ?? ''));

                    $line = "{$n}) {$c['label']}" . ($updated !== '' ? " (zuletzt aktualisiert: {$updated})" : '');

                    // ✅ Symptoms als Kurzbeschreibung
                    if ($symptoms !== '') {
                        // Neue Zeile + eingerückt, damit es optisch dazugehört
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

            // Keyword present, but no forms found: no AI (as requested)
            return [
                'answer' => "Ich habe kein passendes Formular gefunden. Bitte prüfe die Schreibweise oder ergänze 1–2 Stichwörter (z.B. „Etikettendruck Formular“).",
                'matches' => $sops,
                'choices' => [],
                'modeHint' => 'form_kw_empty',
            ];
        }

        // Without form keywords: always store form choices (so numeric selection works),
        // but do NOT early-return. We allow AI to answer normally.
        if ($formChoices !== []) {
            $this->storeChoices($sessionId, $formChoices);
        }

        /**
         * BRANCH C: AI + DB (SOP guidance)
         */
        $history = $this->span($trace, 'cache.history_load', function () use ($sessionId) {
            return $this->loadHistory($sessionId);
        }, [
            'session_hash' => sha1($sessionId),
        ]);

        $history = $this->span($trace, 'history.ensure_system_prompt', function () use ($history) {
            if ($history === []) {
                $history[] = [
                    'role' => 'system',
                    'content' =>
                        'Du bist der IT-Support-Assistent für DashTK. ' .
                        'Führe eine echte Problemdiagnose durch. Stelle gezielte Rückfragen. ' .
                        'Wenn du passende SOPs aus der Wissensdatenbank bekommst, nutze diese vorrangig. ' .
                        'Arbeite Schritt-für-Schritt und frage nach dem Ergebnis nach jedem Schritt.',
                ];
            }
            return $history;
        });

        $history[] = ['role' => 'user', 'content' => $message];

        $kbContext = $this->span($trace, 'kb.build_context', function () use ($matches) {
            return $this->buildKbContext($matches);
        }, [
            'match_count' => count($matches),
        ]);

        $context = $this->span($trace, 'ai.context_defaults', function () use ($context) {
            $context['usage_key'] ??= self::USAGE_KEY_ASK;
            $context['cache_hit'] ??= false;
            return $context;
        }, [
            'usage_key' => $context['usage_key'] ?? self::USAGE_KEY_ASK,
        ]);

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
        }, [
            'provider' => $provider,
        ]);

        $trimmedHistory = $this->span($trace, 'history.trim', function () use ($history) {
            return $this->trimHistory($history);
        }, [
            'history_count_in' => count($history),
            'max' => self::MAX_HISTORY_MESSAGES,
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

            $sopsOnly = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));

            return [
                'answer' => $msg,
                'matches' => $sopsOnly,
                'choices' => $formChoices, // Formulare weiter anzeigen
                'modeHint' => 'ai_with_db',
            ];
        }

        $history[] = ['role' => 'assistant', 'content' => $answer];

        $this->span($trace, 'cache.history_save', function () use ($sessionId, $history) {
            $this->saveHistory($sessionId, $history);
            return null;
        }, [
            'history_count' => count($history),
            'ttl_s' => self::SESSION_TTL_SECONDS,
        ]);

        // SOP choices nur für Numeric-Selection (nicht fürs UI)
        $kbChoices = $this->buildKbChoices($matches);

// Für "1/2/3" speichern wir Forms + SOPs im Cache
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

// UI: matches nur SOPs, choices nur Formulare (damit kein doppelter SOP-Block entsteht)
        $sopsOnly = array_values(array_filter(
            $matches,
            static fn(array $m) => ($m['type'] ?? null) !== 'FORM'
        ));

        return [
            'answer' => $answer,
            'matches' => $sopsOnly,
            'modeHint' => 'ai_with_db',
            'choices' => $formChoices, // ✅ NUR Formulare -> "Auswahl" verschwindet bei reinen SOPs
        ];

    }

    /**
     * Answer a single SOP by ID, returning its step list (DB-only).
     *
     * @param string $sessionId Current session
     * @param int $solutionId SOP ID
     * @return array<string,mixed>
     */
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

    /**
     * Run KB matching in DB to find the best SupportSolutions.
     *
     * @param string $message User query
     * @return array<int, array<string,mixed>> Mapped matches (SOP or FORM)
     */
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

        return $mapped;
    }

    /**
     * Map a SupportSolution entity to a UI-friendly match array.
     *
     * @param SupportSolution $solution
     * @param int $score
     * @return array<string,mixed>
     */
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

    /**
     * Builds AI context from SOP matches only (FORM is excluded).
     *
     * @param array<int, array<string,mixed>> $matches
     * @return string
     */
    private function buildKbContext(array $matches): string
    {
        if ($matches === []) {
            return '';
        }

        $sops = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));
        if ($sops === []) {
            return '';
        }

        $lines = [];
        $lines[] = 'WISSENSDATENBANK (SOPs) – nutze diese vorrangig, wenn passend:';
        foreach ($sops as $hit) {
            $lines[] = sprintf(
                '- SOP #%d: %s (Score %d) IRI: %s',
                $hit['id'],
                $hit['title'],
                $hit['score'],
                $hit['url']
            );
        }
        $lines[] = 'REGEL: Wenn eine SOP passt, führe Schritt-für-Schritt und frage nach jedem Ergebnis.';

        return implode("\n", $lines);
    }

    /**
     * Load chat history from cache.
     *
     * @param string $sessionId
     * @return array<int, array{role:string,content:string}>
     */
    private function loadHistory(string $sessionId): array
    {
        $key = $this->historyCacheKey($sessionId);

        $val = $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(self::SESSION_TTL_SECONDS);
            return [];
        });

        return is_array($val) ? $val : [];
    }

    /**
     * Save chat history into cache.
     *
     * @param string $sessionId
     * @param array<int, array{role:string,content:string}> $history
     */
    private function saveHistory(string $sessionId, array $history): void
    {
        $key = $this->historyCacheKey($sessionId);

        $this->cache->delete($key);

        $this->cache->get($key, function (ItemInterface $item) use ($history) {
            $item->expiresAfter(self::SESSION_TTL_SECONDS);
            return $history;
        });
    }

    /**
     * Keep system prompt + last N messages.
     *
     * @param array<int, array{role:string,content:string}> $history
     * @return array<int, array{role:string,content:string}>
     */
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

    /**
     * Cache key for history.
     */
    private function historyCacheKey(string $sessionId): string
    {
        return 'support_chat.history.' . sha1($sessionId);
    }

    /**
     * Cache key for numeric selection choices.
     */
    private function choicesCacheKey(string $sessionId): string
    {
        return 'support_chat.choices.' . sha1($sessionId);
    }

    /**
     * Store choices list for numeric selection.
     *
     * @param string $sessionId
     * @param array<int, array{kind:string,label:string,payload:array}> $choices
     */
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

    /**
     * Load choices list for numeric selection.
     *
     * @param string $sessionId
     * @return array<int, array{kind:string,label:string,payload:array}>
     */
    private function loadChoices(string $sessionId): array
    {
        $key = $this->choicesCacheKey($sessionId);

        $val = $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(self::CHOICES_TTL_SECONDS);
            return [];
        });

        return is_array($val) ? $val : [];
    }

    /**
     * Resolve numeric selection ("1", "2", "3") into a final answer.
     *
     * Returns NULL if:
     * - message is not a pure integer
     * - or idx <= 0
     *
     * @param string $sessionId
     * @param string $message
     * @return array<string,mixed>|null
     */
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

            // robust: provider/url/id aus payload lesen
            $provider = $payload['externalMediaProvider'] ?? null;
            $externalUrl = $payload['externalMediaUrl'] ?? null;
            $externalId = $payload['externalMediaId'] ?? null;
            $symptoms = (string)($payload['symptoms'] ?? '');

            if ($symptoms !== '') {
                $lines[] = "Hinweis: {$symptoms}";
            }

            // Preview-URL: prefer URL, else derive from provider+id (e.g., Google Drive)
            $previewUrl = $this->formResolver->buildPreviewUrl(
                is_string($provider) ? $provider : null,
                is_string($externalUrl) ? $externalUrl : null,
                is_string($externalId) ? $externalId : null
            );

            // fallback: internal API detail URL
            $fallbackUrl = (string)($payload['url'] ?? '');
            $urlForText = $previewUrl ?: $fallbackUrl;

            $lines = [];
            $lines[] = "✅ **Formular geöffnet:** {$label}";
            if ($updated !== '') {
                $lines[] = "Zuletzt aktualisiert: {$updated}";
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

    /**
     * Build selectable SOP choices from matches (used as fallback for "1/2/3").
     * FORM entries are excluded on purpose (handled via $formChoices in ask()).
     *
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array{kind:string,label:string,payload:array}>
     */
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

    /**
     * Generate a fallback session id when the client did not provide one.
     */
    private function newSessionIdFallback(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Deduplicate matches by solution id (keeps the first occurrence).
     *
     * @param array<int, array<string,mixed>> $matches
     * @return array<int, array<string,mixed>>
     */
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

    /**
     * Entfernt SOP-Treffer, deren Titel bereits als FORM vorkommt.
     * Das verhindert „doppelte“ Anzeige (Formular + SOP mit gleichem Namen).
     *
     * @param array<int, array<string,mixed>> $sops
     * @param array<int, array<string,mixed>> $forms
     * @return array<int, array<string,mixed>>
     */
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

    /**
     * Normalisiert Titel für Vergleich (lowercase, trim, single spaces).
     */
    private function normalizeTitle(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return $t;
    }


}
