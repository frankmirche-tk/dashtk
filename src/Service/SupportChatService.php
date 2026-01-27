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
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;


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
 * Logging (monolog channel: support_solution)
 * - chat_request: before AI call (provider/model, match ids, kb chars)
 * - chat_response: after AI call (answer chars)
 * - db_only_response: after SOP click (steps count)
 * - chat_execute_failed: provider exception
 * - db_matches (debug): mapped matches (optional)
 * - contact_resolved / form_keyword_mode (info): branch decisions (optional)
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
        private readonly NewsletterResolver $newsletterResolver,
        private readonly Connection $db,
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
     *    - If user typed form-keywords (pdf/form/formular/...), then DB-only for forms (no AI)
     *    - Else: expose FORM as choices while still allowing AI for diagnosis
     * 5) AI call with KB context as guidance (SOPs only)
     *
     * @param string $sessionId Session identifier (client provided)
     * @param string $message User message
     * @param int|null $dbOnlySolutionId If set, returns the SOP steps directly (no AI)
     * @param string $provider AI provider name (e.g. gemini/openai)
     * @param string|null $model Optional model override
     * @param array<string,mixed> $context Optional AI context
     * @param Trace|null $trace Optional trace instance
     * @return array<string,mixed> Response payload for the frontend
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

        // neuer Code: "mehr" -> nächste Newsletter-Seite
        if (mb_strtolower($message) === 'mehr') {
            $key = 'support_chat.newsletter_paging.' . sha1($sessionId);

            $paging = $this->cache->get($key, fn(ItemInterface $i) => null);

            if (is_array($paging) && isset($paging['query'], $paging['offset'], $paging['pageSize'], $paging['from'], $paging['to'])) {
                // Simplest: nochmal resolve() mit gleicher Query, aber du würdest hier
                // idealerweise eine Repository-Methode mit offset/limit bauen.
                // Für den ersten Wurf: sag ehrlich, dass Paging backendseitig noch nicht paginiert ist.
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
            $sessionId = $this->span($trace, 'session.fallback_id', function () {
                return $this->newSessionIdFallback();
            });
        }

        // 0) Numeric selection ("1", "2", "3") from last results
        $selection = $this->span($trace, 'choice.try_resolve', function () use ($sessionId, $message) {
            return $this->resolveNumericSelection($sessionId, $message);
        });

        if (is_array($selection)) {
            return $selection;
        }

        // A) DB-only (explicit click from UI)
        if ($dbOnlySolutionId !== null) {
            $result = $this->span($trace, 'db_only.answer', function () use ($sessionId, $dbOnlySolutionId) {
                return $this->answerDbOnly($sessionId, $dbOnlySolutionId);
            }, ['solution_id' => $dbOnlySolutionId]);

            $this->supportSolutionLogger->info('db_only_response', [
                'sessionId'  => $sessionId,
                'solutionId' => $dbOnlySolutionId,
                'stepsCount' => isset($result['steps']) && is_array($result['steps']) ? count($result['steps']) : 0,
            ]);

            return $result;
        }

        /**
         * B0) Local Contact Resolver (privacy: local_only, send_to_ai=false)
         * - We ALWAYS attempt resolve() (cheap/local).
         * - We only return early if intentHit OR actual matches exist.
         */
        $contactHint = $this->span($trace, 'contact.intent.detect', function () use ($message) {
            $m = mb_strtolower($message);

            $keywords = [
                'kontakt', 'kontaktperson', 'ansprechpartner', 'telefon', 'tel', 'email', 'e-mail',
                'filiale', 'filialen', 'standort', 'adresse', 'anschrift', 'öffnungszeiten',
            ];

            // Tokenisiere grob in Wörter, damit "tel" nicht in "bestellung" matched
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $m) ?: [];
            $tokens = array_values(array_filter($tokens, static fn($t) => $t !== ''));

            foreach ($keywords as $k) {
                // "tel" ist kritisch → nur als eigenständiges Token zulassen
                if (in_array($k, $tokens, true)) {
                    return ['hit' => true, 'keyword' => $k];
                }
            }

            // zusätzlich: "tel:" oder "telefon:" explizit erlauben (falls jemand so schreibt)
            if (preg_match('/\b(tel|telefon|e-mail|email)\s*:/u', $m)) {
                return ['hit' => true, 'keyword' => 'contact_explicit'];
            }


            // Kurze Tokens allein sind KEIN sicherer Kontakt-Intent.
            // Sonst blockieren wir KB/Form/SOP-Suche (z.B. "sislo").
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

        /**
         * Nur dann "Kontakt-Modus" ausspielen, wenn
         * - wirklich Kontakt-Keywords im Text sind (intentHit)
         * - oder tatsächlich Matches existieren.
         *
         * Ohne Matches NICHT returnen, sonst blockiert das KB/FORM/SOP Matching.
         */
        $intentHit = (bool)($contactHint['hit'] ?? false);

        // Nur dann "Contact-Mode" zurückgeben, wenn wir wirklich Contacts gefunden haben.
        // Intent alleine darf KB/Forms nicht blockieren.
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
                'answer' => (string)($payload['answer'] ?? ''),
                'matches' => [],
                'modeHint' => 'contact_local',
                'contact' => $contactResult,
                'choices' => $payload['choices'] ?? [],
            ];
        }


        // neuer Code: NewsletterResolver mit Pending-State (Zeitraum First-Class)
        // neuer Code: NewsletterResolver mit Pending-State + Logging (NUR EINMAL im Flow)
        $pendingKey = 'support_chat.newsletter_pending.' . sha1($sessionId);
        $pagingKey  = 'support_chat.newsletter_paging.' . sha1($sessionId);

// 1) Pending? (wir warten nur noch auf Zeitraum)
        $pending = $this->cache->get($pendingKey, static fn(ItemInterface $item) => null);

        if (is_array($pending) && ($pending['awaitingRange'] ?? false) === true) {
            $combined = trim(($pending['query'] ?? 'newsletter') . ' ' . $message);

            $nlPayload = $this->safeResolveNewsletter($sessionId, $combined);


            if (is_array($nlPayload)) {

                // paging cachen falls vorhanden
                if (isset($nlPayload['newsletterPaging']) && is_array($nlPayload['newsletterPaging'])) {
                    $this->cache->delete($pagingKey);
                    $this->cache->get($pagingKey, function (ItemInterface $item) use ($nlPayload) {
                        $item->expiresAfter(1800);
                        return $nlPayload['newsletterPaging'];
                    });
                }

                // Pending löschen sobald wir NICHT mehr nach Zeitraum fragen
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

                // ✅ WICHTIG: choices speichern, damit "1" aufgelöst werden kann
                if (!empty($nlPayload['choices']) && is_array($nlPayload['choices'])) {
                    $this->storeChoices($sessionId, $nlPayload['choices']);
                }

                return $nlPayload;
            }


            // fallback: pending löschen und normal weiter
            $this->cache->delete($pendingKey);
        }

// 2) Normaler Einstieg (User schreibt Newsletter-Intent)
        $nlPayload = $this->safeResolveNewsletter($sessionId, $message);


        if (is_array($nlPayload)) {

            // paging cachen falls vorhanden
            if (isset($nlPayload['newsletterPaging']) && is_array($nlPayload['newsletterPaging'])) {
                $this->cache->delete($pagingKey);
                $this->cache->get($pagingKey, function (ItemInterface $item) use ($nlPayload) {
                    $item->expiresAfter(1800);
                    return $nlPayload['newsletterPaging'];
                });
            }

            // Wenn Resolver nach Zeitraum fragt -> Pending setzen
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

            // ✅ WICHTIG: choices speichern, damit "1" aufgelöst werden kann
            if (!empty($nlPayload['choices']) && is_array($nlPayload['choices'])) {
                $this->storeChoices($sessionId, $nlPayload['choices']);
            }

            return $nlPayload;
        }




        /**
         * B1) KB match (DB) – yields SOP and FORM.
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

        /**
         * Optional (your current behavior):
         * Remove SOP hits whose title already exists as FORM title to avoid "same-name doubles".
         *
         * IMPORTANT:
         * If you WANT a SOP and a FORM with the same title (but different IDs) to show together,
         * then REMOVE the next line.
         */
        $sops = $this->filterSopsDuplicatingFormTitles($sops, $forms);

        // Build FORM choices whenever FORM matches exist
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

        // RULE: AI is NOT used when the search explicitly contains form-keywords.
        $hasFormKeywords = $this->span($trace, 'form.keyword.detect', function () use ($message) {
            return $this->formResolver->hasFormKeywords($message);
        }, [
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

                    // Symptoms as second line (linefeed)
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

            // Keyword present, but no forms found: no AI (as requested)
            return [
                'answer' => "Ich habe kein passendes Formular gefunden. Bitte prüfe die Schreibweise oder ergänze 1–2 Stichwörter (z.B. „Etikettendruck Formular“).",
                'matches' => $sops,
                'choices' => [],
                'modeHint' => 'form_kw_empty',
            ];
        }

        /**
         * Without form keywords:
         * - allow AI to answer normally
         * - still show form choices in UI if available
         * - store choices for numeric selection (forms + sops) but return ONLY formChoices to UI
         */
        if ($formChoices !== []) {
            $this->storeChoices($sessionId, $formChoices);
        }

        /**
         * C) AI + DB (SOP guidance)
         */
        $history = $this->span($trace, 'cache.history_load', function () use ($sessionId) {
            return $this->loadHistory($sessionId);
        }, ['session_hash' => sha1($sessionId)]);

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

        // Important: KB context only from SOPs, never from FORMs
        $kbContext = $this->span($trace, 'kb.build_context', function () use ($matches) {
            return $this->buildKbContext($matches);
        }, ['match_count' => count($matches)]);

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

        $trimmedHistory = $this->span($trace, 'history.trim', function () use ($history) {
            return $this->trimHistory($history);
        }, [
            'history_count_in' => count($history),
            'max' => self::MAX_HISTORY_MESSAGES,
        ]);

        // --- Logging: request
        $this->supportSolutionLogger->info('chat_request', [
            'sessionId'      => $sessionId,
            'message'        => $message,
            'matchCount'     => count($matches),
            'matchIds'       => array_map(static fn($m) => $m['id'] ?? null, $matches),
            'kbContextChars' => strlen($kbContext),
            'provider'       => $provider,
            'model'          => $model,
            'usageKey'       => $context['usage_key'] ?? self::USAGE_KEY_ASK,
        ]);

        $this->supportSolutionLogger->info('ai_cost_debug', [
            'usage_key' => $context['usage_key'] ?? self::USAGE_KEY_ASK,
            'provider'  => $provider,
            'model'     => $model,
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

            // UI: show SOPs only, keep formChoices so forms remain visible even if AI fails
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
        }, [
            'history_count' => count($history),
            'ttl_s' => self::SESSION_TTL_SECONDS,
        ]);

        // --- Logging: response
        $this->supportSolutionLogger->info('chat_response', [
            'sessionId'   => $sessionId,
            'answerChars' => strlen($answer),
            'provider'    => $provider,
            'model'       => $model,
        ]);

        /**
         * Store numeric-selection choices:
         * - store FORMs + SOPs (so "1/2/3" still works for both types)
         * UI should render only formChoices (no SOP duplicates).
         */
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
            'choices' => $formChoices, // ✅ only forms in UI
        ];
    }

    /**
     * Answer a single SOP by ID, returning its step list (DB-only).
     *
     * @param string $sessionId Current session (kept for correlation)
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

        // Optional debug logging (only if you want it; can be noisy)
        $this->supportSolutionLogger->debug('db_matches', [
            'message' => $message,
            'matchCount' => count($mapped),
            'matchIds' => array_map(static fn($x) => $x['id'] ?? null, $mapped),
        ]);

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

    private function historyCacheKey(string $sessionId): string
    {
        return 'support_chat.history.' . sha1($sessionId);
    }

    private function choicesCacheKey(string $sessionId): string
    {
        return 'support_chat.choices.' . sha1($sessionId);
    }

    /**
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
            $symptoms = trim((string)($payload['symptoms'] ?? ''));

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

    /**
     * Build selectable SOP choices from matches (used for numeric "1/2/3").
     * FORM entries are excluded on purpose (handled separately as formChoices).
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
     * ⚠️ Wenn ihr bewusst ein FORM und ein SOP mit gleichem Titel anzeigen wollt,
     * dann diese Funktion NICHT anwenden.
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


    // --- Newsletter Draft helpers (SupportChatService)

    private function newsletterDraftKey(string $draftId): string
    {
        return 'support_chat.newsletter_draft.' . sha1($draftId);
    }

    private function extractDriveId(string $driveUrl): string
    {
        if (preg_match('~/folders/([a-zA-Z0-9_-]+)~', $driveUrl, $m)) {
            return $m[1];
        }
        return '';
    }

    private function mondayOfIsoWeek(int $year, int $kw): \DateTimeImmutable
    {
        $dt = new \DateTimeImmutable();
        return $dt->setISODate($year, $kw, 1)->setTime(0, 0, 0);
    }

    /**
     * POST /api/chat/newsletter/analyze
     */
    public function newsletterAnalyze(
        string $sessionId,
        string $message,
        string $driveUrl,
        ?UploadedFile $file,
        string $provider,
        ?string $model,
        ?Trace $trace = null
    ): array {
        // Newsletter immer OpenAI
        $provider = 'openai';

        $driveUrl = trim($driveUrl);
        $driveId = $this->extractDriveId($driveUrl);

        if ($driveId === '') {
            return [
                'type' => 'need_drive',
                'answer' => 'Mir fehlt der Google-Drive Link (Ordner oder Datei). Bitte füge ihn ein, dann kann ich fortfahren.',
            ];
        }

        if (!$file instanceof UploadedFile) {
            return [
                'type' => 'error',
                'answer' => 'Kein PDF hochgeladen. Bitte Datei auswählen und erneut senden.',
            ];
        }

        // ✅ PDF -> TEXT (pdftotext)
        $text = $this->extractPdfTextWithPdftotext($file->getPathname());
        if (trim($text) === '') {
            return [
                'type' => 'error',
                'answer' => 'PDF-Text-Extraktion fehlgeschlagen (pdftotext liefert leer). Bitte PDF prüfen oder Parser wechseln.',
            ];
        }

        // ✅ Jahr/KW aus Dateiname ziehen (dein Parser soll auch _Teil2 / _Sondernewsletter tolerieren)
        $originalName = $file->getClientOriginalName();
        $parsed = $this->parseYearKwFromFilename($originalName);

        $year = $parsed['year'] ?? null;
        $kw   = $parsed['kw'] ?? null;

        if (!is_int($year) || $year < 2000 || $year > 2100 || !is_int($kw) || $kw < 1 || $kw > 53) {
            return [
                'type' => 'error',
                'answer' =>
                    "Konnte **Jahr/KW** nicht sicher aus dem Dateinamen lesen.\n"
                    . "Erwartet z.B.: `Newsletter_2025_KW53.pdf` oder `Newsletter_2025_KW53_Teil2.pdf`\n"
                    . "Dateiname war: `{$originalName}`",
            ];
        }

        $publishedAt = $this->mondayOfIsoWeek($year, $kw)->format('Y-m-d 00:00:00');
        $now = $this->nowMidnight();

        // ✅ OpenAI Prompt bauen (Headlines in symptoms, beschreibend in context_notes)
        $prompt = $this->buildNewsletterPrompt(
            pdfText: $text,
            originalFilename: $originalName,
            year: $year,
            kw: $kw,
            driveUrl: $driveUrl
        );

        // ✅ AI Call (über Gateway, JSON erzwungen)
        $context = [
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            'usage_key' => 'support_chat.newsletter.analyze',
        ];

        // History so kurz wie möglich: system+user reicht
        $history = [
            ['role' => 'system', 'content' => 'Du bist ein sehr präziser Assistent für Newsletter-Parsing und Datenbank-Drafts. Antworte IMMER als reines JSON-Objekt.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $rawJson = $this->aiChat->chat(
                history: $history,
                kbContext: '',
                provider: $provider,
                model: $model,
                context: $context
            );
        } catch (\Throwable $e) {
            $this->supportSolutionLogger->error('newsletter_ai_failed', [
                'sessionId' => $sessionId,
                'file' => $originalName,
                'year' => $year,
                'kw' => $kw,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return [
                'type' => 'error',
                'answer' => $this->isDev
                    ? ('Newsletter OpenAI Fehler (DEV): ' . $e->getMessage())
                    : 'Newsletter-Analyse ist fehlgeschlagen. Bitte dev.log prüfen.',
            ];
        }

        $ai = $this->decodeNewsletterAiJson($rawJson);

        if ($ai === null) {
            $this->supportSolutionLogger->warning('newsletter_ai_json_invalid', [
                'sessionId' => $sessionId,
                'file' => $originalName,
                'year' => $year,
                'kw' => $kw,
                'raw' => mb_substr($rawJson, 0, 2000),
            ]);

            return [
                'type' => 'error',
                'answer' => "OpenAI hat kein gültiges JSON geliefert. Bitte dev.log prüfen.\n"
                    . ($this->isDev ? ("\nRAW:\n" . mb_substr($rawJson, 0, 1500)) : ''),
            ];
        }

        // ✅ Symptoms: Headlines + Drive-Link Bullet (Format wie gewünscht)
        $symptomsBullets = $this->normalizeSymptomsBullets($ai['symptoms'] ?? []);
        $driveBullet = sprintf('* [%s](%s)', $this->makeDriveLinkLabel($year, $kw, $originalName), $driveUrl);

        // Du willst Links in symptoms im Format "* [Text](url)" -> genau so
        $symptoms = trim(implode("\n", array_values(array_filter($symptomsBullets))));
        $symptoms = $symptoms !== '' ? ($symptoms . "\n" . $driveBullet) : $driveBullet;

        // ✅ context_notes als nummerierte Zusammenfassung (String)
        $contextNotes = trim((string)($ai['context_notes'] ?? ''));
        if ($contextNotes === '') {
            // fallback: wenigstens nachvollziehbar
            $contextNotes = "Quelle: {$originalName}\nNewsletter KW {$kw}/{$year}\n(Automatisch extrahiert, bitte prüfen)";
        }

        // ✅ Keywords (keine Filialkürzel wie COSU/LPSU etc.)
        $keywords = $this->normalizeKeywordsNoBranchCodes($ai['keywords'] ?? []);

        // ✅ Draft vorbereiten (mit AI-Feldern)
        $draftId = bin2hex(random_bytes(16));

        $draft = [
            'type' => 'FORM',
            'title' => "Newsletter KW {$kw}/{$year}",
            'symptoms' => $symptoms,
            'context_notes' => $contextNotes,
            'media_type' => 'external',
            'external_media_provider' => 'google_drive',
            'external_media_url' => $driveUrl,
            'external_media_id' => $driveId,
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $publishedAt,
            'newsletter_year' => $year,
            'newsletter_kw' => $kw,
            'newsletter_edition' => $ai['newsletter_edition'] ?? 'STANDARD',
            'category' => 'NEWSLETTER',
            'keywords' => $keywords,

            // Optional: für Debug/Review im Cache (nicht in DB)
            '_source_text' => $text,
            '_source_filename' => $originalName,
            '_ai_raw' => $ai,
        ];

        // ✅ Draft im Cache speichern (30 min)
        $key = $this->newsletterDraftKey($draftId);
        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $i) use ($draft) {
            $i->expiresAfter(1800);
            return $draft;
        });

        $this->supportSolutionLogger->info('newsletter_ai_ok', [
            'sessionId' => $sessionId,
            'file' => $originalName,
            'year' => $year,
            'kw' => $kw,
            'symptoms_lines' => substr_count($draft['symptoms'], "\n") + 1,
            'keywords_count' => is_array($draft['keywords']) ? count($draft['keywords']) : 0,
        ]);

        return [
            'type' => 'needs_confirmation',
            'answer' => $this->renderNewsletterConfirmText($draft),
            'draftId' => $draftId,
            'confirmCard' => [
                'draftId' => $draftId,
                'fields' => $draft,
            ],
        ];
    }

    /**
     * Prompt klar und lesbar auslagern:
     * - Symptoms: nur Headlines als Bullet-Liste mit "* "
     * - context_notes: nummerierte Kurzbeschreibung (wie dein Beispiel)
     * - keywords: ohne Filialkürzel (COSU etc.)
     */
    private function buildNewsletterPrompt(
        string $pdfText,
        string $originalFilename,
        int $year,
        int $kw,
        string $driveUrl
    ): string {
        // Hinweis: du gibst dem Modell sehr klare Struktur + harte Output-Regeln
        return
            "Ich habe dir einen Newsletter als Text extrahiert (aus PDF) angehängt.\n"
            . "Bitte analysiere den Inhalt und gib mir EIN JSON-Objekt zurück, das ich als DB-Draft nutzen kann.\n\n"

            . "HARTE REGELN:\n"
            . "1) Antworte NUR als JSON (kein Markdown, keine Erklärtexte).\n"
            . "2) 'symptoms' MUSS eine Liste von Headlines sein (kurz, prägnant), jede Headline als eigener String.\n"
            . "   - Im UI werden daraus Bulletpoints mit '* ' gebaut.\n"
            . "   - KEINE Fließtexte in symptoms.\n"
            . "3) 'context_notes' MUSS ein STRING sein mit nummerierten Absätzen (1) 2) 3) ...), beschreibend wie im Beispiel.\n"
            . "4) 'keywords' MUSS eine Liste relevanter Keywords sein, OHNE Filialkürzel.\n"
            . "   - Filialkürzel sind oft 4 Buchstaben groß (z.B. COSU, LPSU). Bitte solche Tokens NICHT ausgeben.\n"
            . "5) Wenn etwas unklar ist, entscheide bestmöglich anhand des Textes.\n\n"

            . "METADATEN:\n"
            . "- Original-Dateiname: {$originalFilename}\n"
            . "- Newsletter Jahr: {$year}\n"
            . "- Newsletter KW: {$kw}\n"
            . "- Drive-Link (nur als Kontext, NICHT in keywords): {$driveUrl}\n\n"

            . "GEWÜNSCHTES JSON-SCHEMA:\n"
            . "{\n"
            . "  \"symptoms\": [\"...\", \"...\"],\n"
            . "  \"context_notes\": \"1) ...\\n\\n2) ...\\n\\n3) ...\",\n"
            . "  \"keywords\": [\"newsletter\", \"umsatz\", \"kundenkarte\", ...],\n"
            . "  \"newsletter_edition\": \"STANDARD\" \n"
            . "}\n\n"

            . "NEWSLETTER-TEXT (AUS PDF EXTRAHIERT):\n"
            . "-----\n"
            . $this->truncateForAi($pdfText, 45000) . "\n"
            . "-----\n";
    }

    /**
     * JSON robust parsen (auch wenn OpenAI davor/danach Whitespace liefert)
     */
    private function decodeNewsletterAiJson(string $raw): ?array
    {
        $raw = trim($raw);

        // best-effort: erstes {...} rausziehen
        $first = strpos($raw, '{');
        $last  = strrpos($raw, '}');
        if ($first === false || $last === false || $last <= $first) {
            return null;
        }

        $json = substr($raw, $first, $last - $first + 1);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Symptoms: wir erwarten array von Headlines (strings).
     * Wir geben hier die Bullet-Strings zurück: "* Headline"
     */
    private function normalizeSymptomsBullets(mixed $symptoms): array
    {
        if (!is_array($symptoms)) {
            return [];
        }

        $out = [];
        foreach ($symptoms as $h) {
            $h = trim((string)$h);
            if ($h === '') {
                continue;
            }
            // falls Modell doch schon "* " liefert -> normalisieren
            $h = ltrim($h);
            $h = preg_replace('/^\*\s*/', '', $h) ?? $h;

            $out[] = '* ' . $h;
            if (count($out) >= 20) break; // Schutz
        }

        return $out;
    }

    /**
     * Keywords: keine Filialkürzel (heuristisch: 4 Großbuchstaben, optional auch mit Zahlen)
     */
    private function normalizeKeywordsNoBranchCodes(mixed $keywords): array
    {
        if (!is_array($keywords)) {
            $keywords = [];
        }

        $base = [
            ['keyword' => 'newsletter', 'weight' => 10],
        ];

        $seen = ['newsletter' => true];

        foreach ($keywords as $k) {
            $k = trim(mb_strtolower((string)$k));
            if ($k === '') continue;

            // Heuristik Filialkürzel:
            // - original könnte "COSU" sein, das wird in lower "cosu" -> checke zusätzlich Upper-Pattern auf input
            // Wir prüfen: wenn Keyword 4 Zeichen und nur Buchstaben -> weg.
            if (preg_match('/^[a-z]{4}$/', $k)) {
                continue;
            }

            // keine ultra-kurzen tokens
            if (mb_strlen($k) < 3) continue;

            if (isset($seen[$k])) continue;
            $seen[$k] = true;

            $base[] = ['keyword' => $k, 'weight' => 6];
            if (count($base) >= 12) break;
        }

        return $base;
    }

    private function makeDriveLinkLabel(int $year, int $kw, string $filename): string
    {
        // Beispiel aus deinem Wunsch: "Newsletter KW52 – Teil 2 (Drive-Ordner)"
        $label = "Newsletter KW{$kw} – Drive-Ordner";
        $lower = mb_strtolower($filename);

        if (str_contains($lower, 'teil2') || str_contains($lower, 'teil_2') || str_contains($lower, 'teil 2')) {
            $label = "Newsletter KW{$kw} – Teil 2 (Drive-Ordner)";
        } elseif (str_contains($lower, 'sondernewsletter')) {
            $label = "Newsletter KW{$kw} – Sondernewsletter (Drive-Ordner)";
        }

        return $label;
    }

    /**
     * damit du nicht aus Versehen riesige PDFs an das Modell schickst
     */
    private function truncateForAi(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, $maxChars) . "\n\n[...TRUNCATED...]";
    }




    /**
     * POST /api/chat/newsletter/patch
     */
    public function newsletterPatch(
        string $sessionId,
        string $draftId,
        string $message,
        string $provider,
        ?string $model,
        ?Trace $trace = null
    ): array {
        $key = $this->newsletterDraftKey($draftId);
        $draft = $this->cache->get($key, static fn(ItemInterface $i) => null);

        if (!is_array($draft)) {
            return ['type' => 'error', 'answer' => 'Draft nicht gefunden/abgelaufen. Bitte Newsletter erneut analysieren.'];
        }

        $msg = trim($message);

        if (preg_match('/published_at\s*(=|auf)\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $msg, $m)) {
            $draft['published_at'] = $m[2] . ' 00:00:00';
        }

        if (preg_match('/created_at\s*(=|auf)\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $msg, $m)) {
            $draft['created_at'] = $m[2] . ' 00:00:00';
            $draft['updated_at'] = $m[2] . ' 00:00:00';
        }

        if (preg_match('~drive[- ]link\s*(=|ist)?\s*(https?://\S+)~i', $msg, $m)) {
            $draft['external_media_url'] = $m[2];
            $draft['external_media_id']  = $this->extractDriveId($m[2]);
        }

        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $i) use ($draft) {
            $i->expiresAfter(1800);
            return $draft;
        });

        return [
            'type' => 'needs_confirmation',
            'answer' => $this->renderNewsletterConfirmText($draft),
            'draftId' => $draftId,
            'confirmCard' => [
                'draftId' => $draftId,
                'fields' => $draft,
            ],
        ];
    }

    /**
     * POST /api/chat/newsletter/confirm
     */
    public function newsletterConfirm(
        string $sessionId,
        string $draftId,
        ?Trace $trace = null
    ): array {
        $key = $this->newsletterDraftKey($draftId);
        $draft = $this->cache->get($key, static fn(ItemInterface $i) => null);

        if (!is_array($draft)) {
            return ['type' => 'error', 'answer' => 'Draft nicht gefunden/abgelaufen. Bitte Newsletter erneut analysieren.'];
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
                'newsletter_year' => (int) $draft['newsletter_year'],
                'newsletter_kw' => (int) $draft['newsletter_kw'],
                'newsletter_edition' => $draft['newsletter_edition'],
                'category' => $draft['category'],
            ]);

            $solutionId = (int) $this->db->lastInsertId();

            $keywords = is_array($draft['keywords'] ?? null) ? $draft['keywords'] : [];
            foreach ($keywords as $kw) {
                if (!is_array($kw)) continue;
                $keyword = trim((string)($kw['keyword'] ?? ''));
                if ($keyword === '') continue;

                $this->db->insert('support_solution_keyword', [
                    'solution_id' => $solutionId,
                    'keyword' => $keyword,
                    'weight' => (int)($kw['weight'] ?? 5),
                ]);
            }

            $this->db->commit();
            $this->cache->delete($key);

            return [
                'type' => 'ok',
                'answer' => "✅ Newsletter wurde eingefügt. ID: {$solutionId}",
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function renderNewsletterConfirmText(array $draft): string
    {
        return "Bitte prüfe vor dem Einfügen:\n\n"
            . "- created_at: **{$draft['created_at']}**\n"
            . "- updated_at: **{$draft['updated_at']}**\n"
            . "- published_at: **{$draft['published_at']}**\n"
            . "- Drive-Link: **{$draft['external_media_url']}**\n"
            . "- Drive-ID: **{$draft['external_media_id']}**\n\n"
            . "Wenn ok, klicke **Einfügen**. Oder schreibe z.B. „published_at auf 2025-12-29“.";
    }

    // ✅ NEU: Helper muss in dieselbe Klasse (SupportChatService)
    private function extractPdfTextWithPdftotext(string $pdfPath): string
    {
        $cmd = sprintf('pdftotext -layout %s -', escapeshellarg($pdfPath));
        $out = shell_exec($cmd);
        return is_string($out) ? trim($out) : '';
    }

    private function parseYearKwFromFilename(string $filename): array
    {
        $raw = (string) $filename;

        // Windows fakepath / Pfade vom Browser wegkürzen
        $name = basename(str_replace('\\', '/', $raw));

        // Whitespaces normalisieren
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name);

        $year = null;
        $kw = null;

        // ✅ kein \b ! (wegen _)
        if (preg_match('/(20\d{2})/u', $name, $m)) {
            $year = (int) $m[1];
        }

        // ✅ KW + 1-2 Ziffern, erlaubt KW03, KW3, KW53
        if (preg_match('/KW\s*0*([0-9]{1,2})/ui', $name, $m)) {
            $kw = (int) $m[1];
        }

        return [
            'year' => $year,
            'kw'   => $kw,
            'raw'  => $name,
        ];
    }


    private function nowMidnight(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d 00:00:00');
    }

}
