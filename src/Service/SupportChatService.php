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
 * Responsibilities:
 * - Maintain chat session history (cache)
 * - Execute local resolvers first (ContactResolver, FormResolver)
 * - Execute KB/DB match for SOP + FORM (SupportSolutionRepository)
 * - Run AI only when appropriate (and/or as fallback)
 * - Provide "choices" for numeric selection UX (user replies "1", "2", "3")
 *
 * Newsletter-Create (Analyze/Patch/Confirm) wurde ausgelagert in NewsletterCreateResolver.
 *
 * --------------------
 * Shared Types (PHPStan/Psalm)
 * --------------------
 *
 * @phpstan-type ChatRole 'system'|'user'|'assistant'
 * @phpstan-type ChatMessage array{role: ChatRole, content: string}
 *
 * @phpstan-type SupportMatch array{
 *   id: int,
 *   title: string,
 *   score: int,
 *   url: string,
 *   type: string,
 *   updatedAt: string,
 *   symptoms: string,
 *   category: string,
 *   newsletterYear?: int|string|null,
 *   newsletterKw?: int|string|null,
 *   newsletterEdition?: string|null,
 *   publishedAt?: string|null,
 *   stepsUrl?: string,
 *   mediaType?: string|null,
 *   externalMediaProvider?: string|null,
 *   externalMediaUrl?: string|null,
 *   externalMediaId?: string|null
 * }
 *
 * @phpstan-type ChoiceKind 'form'|'sop'|'contact'
 * @phpstan-type ChoiceItem array{kind: ChoiceKind, label: string, payload: array<string,mixed>}
 *
 * @phpstan-type AskResponse array{
 *   answer: string,
 *   matches: list<SupportMatch>,
 *   choices: list<ChoiceItem>,
 *   modeHint: string,
 *   contact?: array<string,mixed>,
 *   selected?: ChoiceItem,
 *   formCard?: array{title:string, updatedAt:string, url:string, provider:string, symptoms:string},
 *   newsletterPaging?: array<string,mixed>,
 *   tts?: string,
 *   mediaUrl?: string,
 *   steps?: list<array<string,mixed>>
 * }
 *
 * @phpstan-type PromptPreview array{
 *   provider: string,
 *   model: string|null,
 *   history: list<ChatMessage>,
 *   history_count: int,
 *   kbContext: string,
 *   kb_context_chars: int,
 *   matchCount: int,
 *   matchIds: list<int>
 * }
 *
 * @psalm-type ChatRole = 'system'|'user'|'assistant'
 * @psalm-type ChatMessage = array{role: ChatRole, content: string}
 * @psalm-type SupportMatch = array{
 *   id: int,
 *   title: string,
 *   score: int,
 *   url: string,
 *   type: string,
 *   updatedAt: string,
 *   symptoms: string,
 *   category: string,
 *   newsletterYear?: int|string|null,
 *   newsletterKw?: int|string|null,
 *   newsletterEdition?: string|null,
 *   publishedAt?: string|null,
 *   stepsUrl?: string,
 *   mediaType?: string|null,
 *   externalMediaProvider?: string|null,
 *   externalMediaUrl?: string|null,
 *   externalMediaId?: string|null
 * }
 * @psalm-type ChoiceKind = 'form'|'sop'|'contact'
 * @psalm-type ChoiceItem = array{kind: ChoiceKind, label: string, payload: array<string,mixed>}
 * @psalm-type AskResponse = array{
 *   answer: string,
 *   matches: list<SupportMatch>,
 *   choices: list<ChoiceItem>,
 *   modeHint: string,
 *   contact?: array<string,mixed>,
 *   selected?: ChoiceItem,
 *   formCard?: array{title:string, updatedAt:string, url:string, provider:string, symptoms:string},
 *   newsletterPaging?: array<string,mixed>,
 *   tts?: string,
 *   mediaUrl?: string,
 *   steps?: list<array<string,mixed>>
 * }
 * @psalm-type PromptPreview = array{
 *   provider: string,
 *   model: string|null,
 *   history: list<ChatMessage>,
 *   history_count: int,
 *   kbContext: string,
 *   kb_context_chars: int,
 *   matchCount: int,
 *   matchIds: list<int>
 * }
 */
final class SupportChatService
{
    private const SESSION_TTL_SECONDS = 3600; // 1h
    private const MAX_HISTORY_MESSAGES = 18;

    private const USAGE_KEY_ASK = 'support_chat.ask';

    private const CHOICES_TTL_SECONDS = 1800; // 30min
    private const MAX_CHOICES = 8;

    private readonly bool $isDev;

    /**
     * @param AiChatGateway $aiChat Gateway/Adapter für Provider-spezifische Chats (OpenAI, Gemini, ...)
     * @param SupportSolutionRepository $solutions Repository für SOP/FORM Matches
     * @param CacheInterface $cache Cache für Session-History und Choice-State
     * @param LoggerInterface $supportSolutionLogger Logger für Chat/Modes/Forensics
     * @param UsageTracker $usageTracker Usage/Quota Tracking
     * @param ContactResolver $contactResolver Local-only Resolver für Kontakt/Filialdaten
     * @param FormResolver $formResolver Resolver für Formular-intent + Preview-URL
     * @param PromptTemplateLoader $promptLoader Loader/Renderer für Prompt-Templates inkl. Includes
     * @param NewsletterResolver $newsletterResolver Resolver für Newsletter-Suche (Query)
     * @param NewsletterCreateResolver $newsletterCreateResolver Create-Flow (Analyze/Patch/Confirm)
     * @param FormCreateResolver $documentCreateResolver Document/Create-Flow (Analyze/Patch/Confirm)
     * @param KernelInterface $kernel Für Environment-Flag (dev/prod)
     */
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
    ) {
        $this->isDev = $kernel->getEnvironment() === 'dev';
    }

    /**
     * Convenience trace wrapper to measure sub-operations.
     *
     * @template T
     *
     * @param Trace|null $trace Optional distributed trace for performance profiling
     * @param string $name Span name
     * @param callable():T $fn Callable to execute within span
     * @param array<string,mixed> $meta Metadata attached to the span
     *
     * @return T
     */
    private function span(?Trace $trace, string $name, callable $fn, array $meta = []): mixed
    {
        if ($trace) {
            return $trace->span($name, $fn, $meta);
        }
        return $fn();
    }

    /**
     * Haupt-Einstieg: Orchestriert den Chat.
     *
     * Ablauf (grob):
     * 1) Numeric Selection auf gespeicherte Choices ("1", "2", ...)
     * 2) Optional DB-only SOP (expliziter UI-Click)
     * 3) Local Contact Resolver (privacy: local_only)
     * 4) Newsletter Resolver + Pending Range Handling
     * 5) KB match (SOP/FORM), Form-Keyword Mode (Choice-Liste)
     * 6) AI Fallback/Guidance mit trimHistory + KB_CONTEXT
     *
     * @param string $sessionId Session-ID aus Frontend; wenn leer, wird eine Fallback-ID generiert
     * @param string $message User message
     * @param int|null $dbOnlySolutionId Optional: SOP ID für reinen DB-Antwortmodus
     * @param string $provider Provider-Name (typisch: "gemini"|"openai")
     * @param string|null $model Optional: Model-Override; sonst Default aus ENV/Server
     * @param array<string,mixed> $context Optional: Kontext (usage_key, debug_mode, cache_hit ...)
     * @param Trace|null $trace Optional: Tracing (Performance, Debug)
     *
     * @return array Antwort-Payload für API/Frontend
     *
     * @phpstan-return AskResponse
     * @psalm-return AskResponse
     *
     * @throws \Throwable Falls ein unerwarteter Fehler in Resolvern/Cache/DB auftritt (AI wird intern abgefangen)
     */
    #[TrackUsage(self::USAGE_KEY_ASK, weight: 5)]
    public function ask(
        string  $sessionId,
        string  $message,
        ?int    $dbOnlySolutionId = null,
        string  $provider = 'gemini',
        ?string $model = null,
        array   $context = [],
        ?Trace  $trace = null
    ): array {
        $sessionId = trim($sessionId);
        $rawMessage = trim($message);
        $provider = strtolower(trim($provider));

        $this->span($trace, 'usage.increment', function () {
            $this->usageTracker->increment(self::USAGE_KEY_ASK);
            return null;
        }, ['usage_key' => self::USAGE_KEY_ASK]);

        if ($sessionId === '') {
            $sessionId = $this->span($trace, 'session.fallback_id', fn() => $this->newSessionIdFallback());
        }

        // 0) Numeric selection
        $selection = $this->span($trace, 'choice.try_resolve', fn() => $this->resolveNumericSelection($sessionId, $rawMessage));
        if (is_array($selection)) {
            return $selection;
        }

        // A) DB-only
        if ($dbOnlySolutionId !== null) {
            return $this->span($trace, 'db_only.answer', fn() => $this->answerDbOnly($sessionId, $dbOnlySolutionId), [
                'solution_id' => $dbOnlySolutionId,
            ]);
        }

        // Blacklist immer vor Routing/Matching
        $cleanMessage = $this->applyKeywordBlacklist($rawMessage);
        $mLower = mb_strtolower($cleanMessage);

        // 1.1 / 1.2 Kontakt + Filiale – Early Exit
        $contactResult = $this->span($trace, 'contact.resolve', function () use ($cleanMessage, $trace) {
            return $this->contactResolver->resolve($cleanMessage, 5, $trace);
        }, [
            'policy' => 'local_only',
            'send_to_ai' => false,
            'source' => 'var/data/kontakt_*.json',
        ]);

        if (is_array($contactResult) && !empty($contactResult['matches'])) {
            $choices = [];
            $lines = [];

            $type = (string)($contactResult['type'] ?? 'none');
            $hits = is_array($contactResult['matches']) ? $contactResult['matches'] : [];

            $lines[] = $type === 'branch' ? "Gefundene Filiale(n):"
                : ($type === 'person' ? "Gefundene Kontaktperson(en):" : "Treffer:");

            $i = 1;
            foreach ($hits as $h) {
                $label = (string)($h['label'] ?? '');
                if ($label === '') continue;

                $lines[] = "{$i}) {$label}";
                $choices[] = ['kind' => 'contact', 'label' => $label, 'payload' => $h];

                $i++;
                if ($i > self::MAX_CHOICES) break;
            }

            if ($choices !== []) {
                $lines[] = "";
                $lines[] = "Antworte mit **1–" . count($choices) . "**, um einen Eintrag zu öffnen.";
                $this->storeChoices($sessionId, $choices);
            }

            return [
                'answer' => implode("\n", $lines),
                'matches' => [],
                'choices' => $choices,
                'modeHint' => 'contact_local',
                'contact' => $contactResult,
            ];
        }

        // 1) Tipps/Hilfe
        $isHelp =
            in_array(mb_strtolower($rawMessage), ['tipps', 'hilfe', 'help'], true)
            || str_contains($mLower, 'wie nutze ich den chatbot')
            || str_contains($mLower, 'was kann der bot')
            || str_contains($mLower, 'wie funktioniert das');

        if ($isHelp) {
            $answer =
                "💡 So nutzt du diesen ChatBot effektiv\n\n" .
                "1) Filialinfos: Filialkürzel eingeben (z.B. COSU)\n" .
                "2) Kontakte: Vor- oder Nachname eingeben (z.B. Alina)\n" .
                "3) SOPs: kurzes Stichwort (z.B. Warteschlange)\n" .
                "4) Newsletter/Formulare: mit „Newsletter …“ oder „Formular …“ arbeiten\n\n" .
                "Tipp: Du kannst sehr kurz schreiben – oft reicht ein Stichwort.";

            return [
                'answer' => $answer,
                'matches' => [],
                'choices' => [],
                'modeHint' => 'help',
            ];
        }

        // 2) Newsletter Intent? (Nur hier Datumslogik!)
        $newsletterIntent = (bool)preg_match('/\bnews\s*letter\b|\bnewsletter\b/u', $mLower);
        if ($newsletterIntent) {
            $range = $this->parseNewsletterDateRange($rawMessage);

            // Wenn Datum erkannt wurde: from/to setzen – sonst "alle"
            $from = $range['from'] ?? new \DateTimeImmutable('2000-01-01 00:00:00');
            $to   = $range['to']   ?? (new \DateTimeImmutable('now'))->modify('+10 years');

            $tokens = $this->extractKeywordTokens($cleanMessage, ['newsletter', 'news', 'letter']);

            if ($tokens === []) {
                return [
                    'answer' => "Für Newsletter brauche ich mindestens **1 Keyword** (z.B. „Reduzierungen“, „Filialperformance“). Optional mit Datum („seit 01.01.2026“).",
                    'matches' => [],
                    'choices' => [],
                    'modeHint' => 'newsletter_need_keyword',
                ];
            }

            // Repo liefert Kandidaten (wie gehabt)
            $rows = $this->solutions->findNewsletterMatches($tokens, $from, $to, 0, self::MAX_CHOICES);

            $matches = [];
            foreach ($rows as $r) {
                $sol = $r['solution'] ?? null;
                if ($sol instanceof SupportSolution) {
                    $matches[] = $this->mapMatch($sol, 100);
                }
            }

            // ✅ FIX: Wenn Datum im Request -> STRICT nach publishedAt filtern (und NUR im Newsletter-Intent)
            if ($range !== null) {
                $before = count($matches);
                $matches = $this->filterNewsletterMatchesStrictByPublishedAt($matches, $from, $to);

                $this->supportSolutionLogger->debug('newsletter.strict_published_filter', [
                    'query_raw' => $rawMessage,
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'before' => $before,
                    'after' => count($matches),
                ]);
            }

            if ($matches === []) {
                return [
                    'answer' => "Ich habe **keinen Newsletter** gefunden, der zu **" . implode(', ', $tokens) . "** passt" .
                        ($range ? (" (Zeitraum: " . $from->format('Y-m-d') . " bis " . $to->format('Y-m-d') . ").") : "."),
                    'matches' => [],
                    'choices' => [],
                    'modeHint' => 'newsletter_empty',
                ];
            }

            $choices = [];
            $lines = ["Gefundene **Newsletter**:"];
            $i = 1;
            foreach ($matches as $m) {
                $label = (string)($m['title'] ?? '');
                $pub = (string)($m['publishedAt'] ?? '');
                $line = $label . ($pub !== '' ? " ({$pub})" : '');
                $lines[] = "{$i}) {$line}";
                $choices[] = ['kind' => 'form', 'label' => $line, 'payload' => $m];
                $i++;
                if ($i > self::MAX_CHOICES) break;
            }

            $lines[] = "";
            $lines[] = "Antworte mit **1–" . count($choices) . "**, um einen Newsletter zu öffnen.";

            $this->storeChoices($sessionId, $choices);

            return [
                'answer' => implode("\n", $lines),
                'matches' => $matches,
                'choices' => $choices,
                'modeHint' => 'newsletter_only',
            ];
        }

        // 3) Form Intent?
        $formIntent = (bool)preg_match('/\b(form|formular|dokument|antrag|vertrag)(e|en|er)?\b/u', $mLower);
        if ($formIntent) {
            $tokens = $this->extractKeywordTokens($cleanMessage, ['form', 'formular', 'dokument', 'antrag', 'vertrag']);

            if ($tokens === []) {
                return [
                    'answer' => "Für Formulare brauche ich mindestens **1 Keyword** (z.B. „Reisekosten“, „Urlaub“).",
                    'matches' => [],
                    'choices' => [],
                    'modeHint' => 'form_need_keyword',
                ];
            }

            $qb = $this->solutions->createQueryBuilder('s')
                ->join('s.keywords', 'k')
                ->andWhere('s.active = true')
                ->andWhere('s.type = :type')
                ->andWhere('s.category = :category')
                ->andWhere('LOWER(k.keyword) IN (:tokens)')
                ->setParameter('type', 'FORM')
                ->setParameter('category', 'GENERAL')
                ->setParameter('tokens', array_map('mb_strtolower', $tokens))
                ->addOrderBy('s.priority', 'DESC')
                ->setMaxResults(self::MAX_CHOICES);

            $solutions = $qb->getQuery()->getResult();

            $matches = [];
            foreach ($solutions as $sol) {
                if ($sol instanceof SupportSolution) {
                    $matches[] = $this->mapMatch($sol, 100);
                }
            }

            if ($matches === []) {
                return [
                    'answer' => "Ich habe **kein Formular/Dokument** gefunden, das zu **" . implode(', ', $tokens) . "** passt.",
                    'matches' => [],
                    'choices' => [],
                    'modeHint' => 'form_empty',
                ];
            }

            $choices = [];
            $lines = ["Gefundene **Formulare/Dokumente**:"];
            $i = 1;
            foreach ($matches as $m) {
                $label = (string)($m['title'] ?? '');
                $lines[] = "{$i}) {$label}";
                $choices[] = ['kind' => 'form', 'label' => $label, 'payload' => $m];
                $i++;
                if ($i > self::MAX_CHOICES) break;
            }

            $lines[] = "";
            $lines[] = "Antworte mit **1–" . count($choices) . "**, um ein Dokument zu öffnen.";

            $this->storeChoices($sessionId, $choices);

            return [
                'answer' => implode("\n", $lines),
                'matches' => $matches,
                'choices' => $choices,
                'modeHint' => 'form_only',
            ];
        }

        // 4) ELSE: Klassisch KI (hier KEINE Datumslogik für Newsletter!)
        $tokens = $this->extractKeywordTokens($cleanMessage, []);
        $hasKeyword = false;

        if ($tokens !== [] && method_exists($this->solutions, 'hasAnyKeywordMatch')) {
            $hasKeyword = (bool)$this->solutions->hasAnyKeywordMatch($tokens);
        }

        $matches = [];
        if ($hasKeyword) {
            $matches = $this->span($trace, 'kb.match', fn() => $this->findMatches($cleanMessage), [
                'query_len' => mb_strlen($cleanMessage),
            ]);
            $matches = $this->dedupeMatchesById($matches);
        } else {
            $this->supportSolutionLogger->debug('kb.skip_no_keyword_hit', [
                'message' => $cleanMessage,
                'tokens' => $tokens,
            ]);
        }

        $forms = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) === 'FORM'));
        $sops  = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));

        $formChoices = [];
        if ($forms !== []) {
            $i = 1;
            foreach ($forms as $f) {
                $title = (string)($f['title'] ?? '');
                if ($title === '') continue;
                $formChoices[] = ['kind' => 'form', 'label' => $title, 'payload' => $f];
                $i++;
                if ($i > self::MAX_CHOICES) break;
            }
        }
        if ($formChoices !== []) {
            $this->storeChoices($sessionId, $formChoices);
        }

        $context['usage_key'] ??= self::USAGE_KEY_ASK;

        $history = $this->span($trace, 'cache.history_load', fn() => $this->loadHistory($sessionId), [
            'session_hash' => sha1($sessionId),
        ]);

        $history = $this->span($trace, 'history.ensure_system_prompt', function () use ($history) {
            $tpl = $this->promptLoader->load('KiChatBotPrompt.config');
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
            $expectedSystem = $this->promptLoader->render($tpl['system'], ['today' => $today]);

            $systemIndex = null;
            foreach ($history as $i => $msg) {
                if (($msg['role'] ?? null) === 'system') { $systemIndex = $i; break; }
            }
            if ($systemIndex === null) {
                array_unshift($history, ['role' => 'system', 'content' => $expectedSystem]);
                return $history;
            }

            $history[$systemIndex]['content'] = $expectedSystem;
            if ($systemIndex !== 0) {
                $sys = $history[$systemIndex];
                unset($history[$systemIndex]);
                array_unshift($history, $sys);
                $history = array_values($history);
            }
            return $history;
        });

        $history[] = ['role' => 'user', 'content' => $rawMessage];

        $kbContext = $matches !== [] ? $this->buildKbContext($matches) : '';

        $trimmedHistory = $this->span($trace, 'history.trim', fn() => $this->trimHistory($history), [
            'history_count_in' => count($history),
            'max' => self::MAX_HISTORY_MESSAGES,
        ]);

        $answer = $this->span($trace, 'ai.call', function () use ($trimmedHistory, $kbContext, $provider, $model, $context) {
            return $this->aiChat->chat(
                history: $trimmedHistory,
                kbContext: $kbContext,
                provider: $provider,
                model: $model,
                context: $context
            );
        });

        $history[] = ['role' => 'assistant', 'content' => $answer];
        $this->span($trace, 'cache.history_save', function () use ($sessionId, $history) {
            $this->saveHistory($sessionId, $history);
            return null;
        });

        return [
            'answer' => $answer,
            'matches' => $sops,
            'modeHint' => $hasKeyword ? 'ai_with_db' : 'ai_only',
            'choices' => $formChoices,
            'provider' => $provider,
            'model' => $model,
            '_meta' => [
                'ai_used' => true,
                'kb_used' => $hasKeyword,
            ],
        ];
    }


    // ---------------------------------------------------------------------
    // Newsletter Create (delegiert)
    // ---------------------------------------------------------------------

    /**
     * POST /api/chat/newsletter/analyze
     * => kompletter Create-Flow ist im NewsletterCreateResolver.
     *
     * @return array<string,mixed> Resolver-Antwort (analyze payload)
     */
    public function newsletterAnalyze(
        string        $sessionId,
        string        $message,
        string        $driveUrl,
        ?UploadedFile $file,
        string        $provider,
        ?string       $model,
        ?Trace        $trace = null
    ): array {
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
     *
     * @return array<string,mixed> Resolver-Antwort (patch payload)
     */
    public function newsletterPatch(
        string  $sessionId,
        string  $draftId,
        string  $message,
        string  $provider,
        ?string $model,
        ?Trace  $trace = null
    ): array {
        return $this->newsletterCreateResolver->patch(
            sessionId: $sessionId,
            draftId: $draftId,
            message: $message
        );
    }

    /**
     * POST /api/chat/newsletter/confirm
     *
     * @return array<string,mixed> Resolver-Antwort (confirm payload)
     */
    public function newsletterConfirm(
        string $sessionId,
        string $draftId,
        ?Trace $trace = null
    ): array {
        return $this->newsletterCreateResolver->confirm(
            sessionId: $sessionId,
            draftId: $draftId
        );
    }

    // ---------------------------------------------------------------------
    // DB-only SOP
    // ---------------------------------------------------------------------

    /**
     * DB-only SOP Antwort (ohne AI).
     *
     * @param string $sessionId Session-ID (für Konsistenz; wird aktuell nicht zwingend verwendet)
     * @param int $solutionId SupportSolution ID
     *
     * @return array<string,mixed> SOP Antwort inkl. Steps-Payload
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
     * Public wrapper for CLI/debug tools (e.g. app:chat:prompt-preview).
     * Keeps internal matching implementation encapsulated (findMatches stays private).
     *
     * @param string $message User query
     *
     * @return array<int, array<string, mixed>> Trefferliste (gemappt)
     *
     * @phpstan-return list<SupportMatch>
     * @psalm-return list<SupportMatch>
     */
    public function matchSolutions(string $message): array
    {
        /** @var list<SupportMatch> $matches */
        $matches = $this->findMatches($message);
        return $matches;
    }

    // ---------------------------------------------------------------------
    // KB match / mapping
    // ---------------------------------------------------------------------

    /**
     * Finds best matching solutions from repository and maps them to a lightweight array shape.
     *
     * @param string $message User query
     *
     * @return array<int, array<string,mixed>>
     *
     * @phpstan-return list<SupportMatch>
     * @psalm-return list<SupportMatch>
     */
    private function findMatches(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return [];
        }

        // Blacklist IMMER (vor jeder DB-Nutzung)
        $message = $this->applyKeywordBlacklist($message);

        // 1) Primär: Scoring-Matcher
        $raw = $this->solutions->findBestMatches($message, 8);

        $mapped = [];
        foreach ($raw as $m) {
            $s = $m['solution'] ?? null;
            if (!$s instanceof SupportSolution) {
                continue;
            }
            $mapped[] = $this->mapMatch($s, (int)($m['score'] ?? 0));
        }

        // 2) Fallback: direkter Keyword-LIKE Match (damit Keywords garantiert greifen)
        if ($mapped === []) {
            $needle = mb_strtolower($message);

            $parts = preg_split('/[^\p{L}\p{N}]+/u', $needle) ?: [];
            $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

            // Fallback nutzt erstes sinnvolles Token
            $q = '';
            foreach ($parts as $p) {
                if (mb_strlen($p) >= 3 && preg_match('/\p{L}/u', $p)) {
                    $q = $p;
                    break;
                }
            }

            if ($q !== '') {
                // Query: alle SupportSolutions, die ein Keyword haben, das mit Token beginnt
                // (du kannst %...% nehmen, aber Prefix ist meist besser)
                $qb = $this->solutions->createQueryBuilder('s')
                    ->join('s.keywords', 'k')
                    ->andWhere('s.active = true')
                    ->andWhere('LOWER(k.keyword) LIKE :q')
                    ->setParameter('q', $q . '%')
                    ->addOrderBy('s.priority', 'DESC')
                    ->setMaxResults(8);

                $solutions = $qb->getQuery()->getResult();

                foreach ($solutions as $sol) {
                    if ($sol instanceof SupportSolution) {
                        $mapped[] = $this->mapMatch($sol, 1);
                    }
                }
            }

            $mapped = $this->dedupeMatchesById($mapped);

            $this->supportSolutionLogger->debug('db_matches_fallback_like', [
                'message' => $message,
                'fallbackToken' => $q,
                'matchCount' => count($mapped),
                'matchIds' => array_map(static fn($x) => $x['id'] ?? null, $mapped),
            ]);
        }

        $this->supportSolutionLogger->debug('db_matches', [
            'message' => $message,
            'matchCount' => count($mapped),
            'matchIds' => array_map(static fn($x) => $x['id'] ?? null, $mapped),
        ]);

        return $mapped;
    }



    /**
     * Maps SupportSolution entity to match payload consumed by UI/AI context builder.
     *
     * @param SupportSolution $solution Entity
     * @param int $score Relevance score from repository match
     *
     * @return array<string,mixed>
     *
     * @phpstan-return SupportMatch
     * @psalm-return SupportMatch
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
            'category' => (string)($solution->getCategory() ?? ''),
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

    /**
     * Builds a compact KB (Knowledge Base) context block for the AI model.
     *
     * Ziel:
     * - SOP/Other (technisch/operativ) soll im Kontext zuerst stehen.
     * - Newsletter sind reine Info (zeitlich filterbar), aber ohne Priorität gegenüber SOP.
     * - Forms sind Keyword-Ja/Nein (Auswahl/Nummern anbieten, falls vorhanden).
     *
     * @param array<int, array<string,mixed>> $matches Mapped matches (SupportMatch shape)
     *
     * @return string KB context text (empty string if no matches)
     *
     * @phpstan-param list<SupportMatch> $matches
     * @psalm-param list<SupportMatch> $matches
     */
    private function buildKbContext(array $matches): string
    {
        if ($matches === []) {
            return '';
        }

        $items = array_values($matches);
        if ($items === []) {
            return '';
        }

        $newsletters = [];
        $forms = [];
        $others = [];

        foreach ($items as $m) {
            $type = (string)($m['type'] ?? '');
            $cat  = mb_strtolower((string)($m['category'] ?? ''));
            $ttl  = mb_strtolower((string)($m['title'] ?? ''));

            // 1) Newsletter: ausschließlich über category / Newsletter-Felder (NICHT über $type!)
            // Unterstützt sowohl snake_case als auch camelCase Keys.
            $nlYear    = (string)($m['newsletter_year'] ?? $m['newsletterYear'] ?? '');
            $nlKw      = (string)($m['newsletter_kw'] ?? $m['newsletterKw'] ?? '');
            $nlEdition = (string)($m['newsletter_edition'] ?? $m['newsletterEdition'] ?? '');
            $published = (string)($m['published_at'] ?? $m['publishedAt'] ?? '');

            $isNewsletter = ($cat === 'newsletter')
                || ($nlYear !== '' || $nlKw !== '' || $nlEdition !== '' || $published !== '')
                || str_contains($ttl, 'newsletter');

            if ($isNewsletter) {
                $newsletters[] = $m;
                continue;
            }

            // 2) Formulare
            if ($type === 'FORM') {
                $forms[] = $m;
                continue;
            }

            // 3) SOPs / Other (Default-Fall)
            // SOP ist bei euch "DB-only" ohne Media-Link -> bleibt in OTHER_KB_MATCHES.
            $others[] = $m;
        }

        $lines = [];
        $lines[] = "KB_CONTEXT: present";
        $lines[] = "KONTEXT: Interne Wissensdatenbank-Treffer (zur Beantwortung der Nutzerfrage).";
        $lines[] = "HINWEIS: Gib nur eine normale Nutzer-Antwort aus (keine internen Hinweise, keine Meta-Erklärungen).";
        $lines[] = "";

        $lines[] = "SOP_TREFFER:";
        if ($others === []) {
            $lines[] = "- keine";
        } else {
            foreach ($others as $hit) {
                $lines[] = sprintf(
                    "- (#%d) %s (Score %d) IRI: %s",
                    (int)($hit['id'] ?? 0),
                    (string)($hit['title'] ?? ''),
                    (int)($hit['score'] ?? 0),
                    (string)($hit['url'] ?? '')
                );
            }
        }

        $lines[] = "";
        $lines[] = "FORM_TREFFER:";
        if ($forms === []) {
            $lines[] = "- keine";
        } else {
            foreach ($forms as $hit) {
                $id       = (int)($hit['id'] ?? 0);
                $title    = trim((string)($hit['title'] ?? ''));
                $symptoms = trim((string)($hit['symptoms'] ?? ''));
                $lines[] = "- (#{$id}) {$title}";
                if ($symptoms !== '') {
                    $lines[] = "  excerpt: " . $symptoms;
                }
            }
        }

        $lines[] = "";
        $lines[] = "NEWSLETTER_TREFFER:";
        if ($newsletters === []) {
            $lines[] = "- keine";
        } else {
            foreach ($newsletters as $hit) {
                $id        = (int)($hit['id'] ?? 0);
                $title     = trim((string)($hit['title'] ?? ''));
                $kw        = (string)($hit['newsletter_kw'] ?? '');
                $year      = (string)($hit['newsletter_year'] ?? '');
                $edition   = (string)($hit['newsletter_edition'] ?? '');
                $published = (string)($hit['published_at'] ?? '');
                $symptoms  = trim((string)($hit['symptoms'] ?? ''));

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


        return implode("\n", $lines) . "\n";
    }


    // ---------------------------------------------------------------------
    // History / choices cache
    // ---------------------------------------------------------------------

    /**
     * Loads session chat history from cache.
     *
     * @param string $sessionId Session ID
     *
     * @return array<int, array<string,mixed>>
     *
     * @phpstan-return list<ChatMessage>
     * @psalm-return list<ChatMessage>
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
     * Persists session chat history to cache (overwrites old state).
     *
     * @param string $sessionId Session ID
     * @param array<int, array<string,mixed>> $history Chat history (ChatMessage list)
     *
     * @phpstan-param list<ChatMessage> $history
     * @psalm-param list<ChatMessage> $history
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
     * Trims history to configured maximum while keeping system message first (if present).
     *
     * @param array<int, array<string,mixed>> $history Chat history
     *
     * @return array<int, array<string,mixed>> Trimmed history
     *
     * @phpstan-param list<ChatMessage> $history
     * @phpstan-return list<ChatMessage>
     * @psalm-param list<ChatMessage> $history
     * @psalm-return list<ChatMessage>
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
     *
     * @param string $sessionId Session ID
     */
    private function historyCacheKey(string $sessionId): string
    {
        return 'support_chat.history.' . sha1($sessionId);
    }

    /**
     * Cache key for numeric selection choices.
     *
     * @param string $sessionId Session ID
     */
    private function choicesCacheKey(string $sessionId): string
    {
        return 'support_chat.choices.' . sha1($sessionId);
    }

    /**
     * Stores selectable choices (form/sop/contact) for later numeric selection.
     *
     * @param string $sessionId Session ID
     * @param array<int, array<string,mixed>> $choices Choice list
     *
     * @phpstan-param list<ChoiceItem> $choices
     * @psalm-param list<ChoiceItem> $choices
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
     * Loads selectable choices from cache.
     *
     * @param string $sessionId Session ID
     *
     * @return array<int, array<string,mixed>>
     *
     * @phpstan-return list<ChoiceItem>
     * @psalm-return list<ChoiceItem>
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

    // ---------------------------------------------------------------------
    // Numeric selection
    // ---------------------------------------------------------------------

    /**
     * Resolves numeric selection ("1", "2", ...) against stored choices.
     *
     * @param string $sessionId Session ID
     * @param string $message Raw user message (expected to be a number)
     *
     * @return array<string,mixed>|null Returns a response payload or null if message is not numeric
     *
     * @phpstan-return AskResponse|null
     * @psalm-return AskResponse|null
     */
    private function resolveNumericSelection(string $sessionId, string $message): ?array
    {
        // ... dein bestehender Code (unverändert)
        // (Hier bleibt der Body 1:1 wie in deinem Snippet; PHPDoc ist der relevante Teil.)
        $m = trim($message);
        if ($m === '' || !preg_match('/^\d+$/', $m)) {
            return null;
        }

        $idx = (int)$m;
        if ($idx <= 0) {
            return null;
        }

        /** @var list<ChoiceItem> $choices */
        $choices = $this->loadChoices($sessionId);

        if ($choices === []) {
            return [
                'answer' => "Ich habe keine Auswahl mehr gespeichert. Bitte formuliere die Anfrage erneut (z.B. „Formular Reisekosten“).",
                'matches' => [],
                'choices' => [],
                'modeHint' => 'choice_empty',
            ];
        }

        $choice = $choices[$idx - 1] ?? null;
        if (!is_array($choice)) {
            return [
                'answer' => "Bitte wähle eine Zahl zwischen 1 und " . count($choices) . ".",
                'matches' => [],
                'choices' => $choices,
                'modeHint' => 'choice_out_of_range',
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
                'choices' => [],
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
            'choices' => [],
            'modeHint' => 'choice_unknown',
        ];
    }

    /**
     * Builds SOP choices from KB matches (excluding forms).
     *
     * @param array<int, array<string,mixed>> $matches
     *
     * @return array<int, array<string,mixed>>
     *
     * @phpstan-param list<SupportMatch> $matches
     * @phpstan-return list<ChoiceItem>
     * @psalm-param list<SupportMatch> $matches
     * @psalm-return list<ChoiceItem>
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
     * Generates a fallback session id if none is provided by client.
     */
    private function newSessionIdFallback(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Deduplicates matches by their integer id (keeps first occurrence).
     *
     * @param array<int, array<string,mixed>> $matches
     *
     * @return array<int, array<string,mixed>>
     *
     * @phpstan-param list<SupportMatch> $matches
     * @phpstan-return list<SupportMatch>
     * @psalm-param list<SupportMatch> $matches
     * @psalm-return list<SupportMatch>
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
     * Filters SOPs whose title duplicates a form title (normalized).
     *
     * @param array<int, array<string,mixed>> $sops
     * @param array<int, array<string,mixed>> $forms
     *
     * @return array<int, array<string,mixed>>
     *
     * @phpstan-param list<SupportMatch> $sops
     * @phpstan-param list<SupportMatch> $forms
     * @phpstan-return list<SupportMatch>
     * @psalm-param list<SupportMatch> $sops
     * @psalm-param list<SupportMatch> $forms
     * @psalm-return list<SupportMatch>
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
     * Normalizes titles for deduplication (lowercase + collapsed whitespace).
     */
    private function normalizeTitle(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return $t;
    }

    /**
     * Safe wrapper around NewsletterResolver->resolve() with logging.
     *
     * @param string $sessionId Session ID (for logs)
     * @param string $query Newsletter query
     *
     * @return array<string,mixed>|null Resolver payload or null if resolver returns null
     */


    // ---------------------------------------------------------------------
    // Document Create (delegiert)
    // ---------------------------------------------------------------------

    /**
     * POST /api/chat/document/analyze
     * => kompletter Create-Flow ist im FormCreateResolver.
     *
     * @return array<string,mixed>
     */
    public function documentAnalyze(
        string        $sessionId,
        string        $message,
        string        $driveUrl,
        ?UploadedFile $file,
        string        $provider,
        ?string       $model,
        ?Trace        $trace = null
    ): array {
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
     *
     * @return array<string,mixed>
     */
    public function documentPatch(
        string  $sessionId,
        string  $draftId,
        string  $message,
        string  $provider,
        ?string $model,
        ?Trace  $trace = null
    ): array {
        return $this->documentCreateResolver->patch(
            sessionId: $sessionId,
            draftId: $draftId,
            message: $message
        );
    }

    /**
     * POST /api/chat/document/confirm
     *
     * @return array<string,mixed>
     */
    public function documentConfirm(
        string $sessionId,
        string $draftId,
        ?Trace $trace = null
    ): array {
        return $this->documentCreateResolver->confirm(
            sessionId: $sessionId,
            draftId: $draftId
        );
    }

    /**
     * Baut den finalen Prompt (History + KB-Context + Defaults) **genau wie im Live-Chat**,
     * führt aber **KEINEN** Request an den KI-Provider aus.
     *
     * Unterschiede zum Live-Chat:
     * - Für Preview wird standardmäßig eine "fresh history" gebaut:
     *   **nur** system + aktuelle user message (keine Session-History aus Cache).
     *   Das macht Debugging reproduzierbar und verhindert, dass alte History das Preview verfälscht.
     *
     * Typische Use-Cases:
     * - Debugging von Prompt/Includes/Render-Variablen (today)
     * - Kontrolle über KB_CONTEXT Inhalt, Längen, Treffer-IDs
     * - Provider/Model-Resolution testen ohne AI-Kosten/Latency
     *
     * @param string $sessionId Session-ID (wird getrimmt; leer => akzeptiert, aber nicht genutzt außer Logging/Kompatibilität)
     * @param string $message   User-Eingabe, die als letzte Message in die Preview-History kommt
     * @param string $provider  Provider-Name (üblich: "gemini"|"openai"); wird lowercased
     * @param string|null $model Optionales Model-Override; wenn null/leer -> Default aus ENV/Server wie im Live-Chat
     * @param array<string,mixed> $context Optionaler Kontext (z.B. usage_key/debug flags). Wird hier nur minimal normalisiert.
     *
     * @return array{
     *   provider: string,
     *   model: string|null,
     *   history: list<array{role:'system'|'user'|'assistant', content:string}>,
     *   history_count: int,
     *   kbContext: string,
     *   kb_context_chars: int,
     *   matchCount: int,
     *   matchIds: list<int>
     * }
     *
     * @phpstan-return PromptPreview
     * @psalm-return PromptPreview
     */
    public function previewPrompt(
        string $sessionId,
        string $message,
        string $provider = 'gemini',
        ?string $model = null,
        array $context = [],
    ): array {
        $sessionId = trim($sessionId);
        $message   = trim($message);
        $provider  = strtolower(trim($provider));

        // 1) KB match wie im Chat
        /** @var list<SupportMatch> $matches */
        $matches = $this->kbMatch($message);

        // 2) System Prompt exakt wie im Live-Chat sicherstellen (render + marker)
        $tpl = $this->promptLoader->load('KiChatBotPrompt.config');

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $system = (string)($tpl['system'] ?? '');
        $system = $this->promptLoader->render($system, ['today' => $today]);

        // Optionaler Marker (falls ihr ihn immer erzwingen wollt)
        $marker = 'PROMPT_ID: dashTk_assist_v2';
        if ($marker !== '' && stripos($system, $marker) === false) {
            $system = $marker . "\n" . $system;
        }

        // 3) Preview-History "fresh": NUR system + aktuelle user message
        /** @var list<ChatMessage> $history */
        $history = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $message],
        ];

        // 4) KB Context bauen
        $kbContext = $this->buildKbContext($matches);

        // 5) Defaults wie im Live-Chat (minimal)
        $context['usage_key'] ??= self::USAGE_KEY_ASK;
        $context['cache_hit'] ??= false;

        // 6) Model Resolve wie im Live-Chat (nur falls nicht übergeben)
        if ($model === null || trim($model) === '') {
            if ($provider === 'openai') {
                $model = (string)($_ENV['OPENAI_DEFAULT_MODEL'] ?? $_SERVER['OPENAI_DEFAULT_MODEL'] ?? '');
            } elseif ($provider === 'gemini') {
                $model = (string)($_ENV['GEMINI_DEFAULT_MODEL'] ?? $_SERVER['GEMINI_DEFAULT_MODEL'] ?? '');
            }
            $model = trim((string)$model);
            $model = $model !== '' ? $model : null;
        }

        // 7) Trim wie im Live-Chat (hier praktisch nur Safety, bleibt aber konsistent)
        /** @var list<ChatMessage> $trimmedHistory */
        $trimmedHistory = $this->trimHistory($history);

        // Match IDs als list<int> (sauber für JSON/Logs/CLI)
        $matchIds = [];
        foreach ($matches as $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id > 0) {
                $matchIds[] = $id;
            }
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'history' => $trimmedHistory,
            'history_count' => count($trimmedHistory),
            'kbContext' => $kbContext,
            'kb_context_chars' => strlen($kbContext),
            'matchCount' => count($matches),
            'matchIds' => $matchIds,
        ];
    }


    /**
     * Führt den Chat wirklich aus (wie Live), basierend auf previewPrompt().
     * Gibt zusätzlich provider_used und answer zurück, um Routing/Filter testen zu können.
     *
     * @param string $sessionId
     * @param string $message
     * @param string $provider 'openai'|'gemini'|'auto' (auto = Gateway darf routen)
     * @param string|null $model
     * @param array $context
     *
     * @return array<string,mixed>
     */
    public function executePrompt(
        string $sessionId,
        string $message,
        string $provider = 'auto',
        ?string $model = null,
        array $context = [],
    ): array {
        // 1) Preview bauen (inkl. KB Matches & kbContext)
        $preview = $this->previewPrompt(
            sessionId: $sessionId,
            message: $message,
            provider: $provider === 'auto' ? 'openai' : $provider, // preview braucht irgendeinen provider für model-resolve
            model: $model,
            context: $context,
        );

        /** @var list<ChatMessage> $history */
        $history = $preview['history'];
        $kbContext = (string)($preview['kbContext'] ?? '');

        // 2) Kontext anreichern, damit pickProvider() im Gateway sauber routen kann
        $context['usage_key']  ??= self::USAGE_KEY_ASK;
        $context['mode_hint']  ??= 'ai_with_db';
        $context['kb_matches'] ??= []; // falls ihr sie später explizit reingebt

        // WICHTIG: kb_matches aus preview/matches übernehmen (damit SOP/FORM erkannt wird)
        // -> du hast matchIds im Preview, aber nicht die Match-Objekte.
        // -> daher: previewPrompt() sollte zusätzlich 'matches' zurückgeben ODER wir matchen hier nochmal.
        // Sauberste Lösung: hier nochmal kbMatch() aufrufen:
        $context['kb_matches'] = $this->kbMatch($message);

        // 3) Provider "auto" => Gateway darf routen (provider=null)
        $providerForGateway = ($provider === 'auto') ? null : $provider;

        // 4) Execute
        $modelForGateway = ($provider === 'auto') ? null : ($preview['model'] ?? null);

        $answer = $this->aiChat->chat(
            history: $history,
            kbContext: $kbContext,
            provider: $providerForGateway,
            model: $modelForGateway,
            context: $context,
        );

        // 5) Response
        $preview['provider_used'] = $providerForGateway ?? 'auto';
        $preview['answer'] = $answer;
        return $preview;
    }


    /**
     * Kapselt die vorhandene Match-Logik, damit previewPrompt() exakt dieselben Treffer bekommt.
     * Diese Methode existiert bewusst als "Adapter", damit intern refactored werden kann,
     * ohne dass Preview/CLI-Tools brechen.
     *
     * @param string $message User-Query
     *
     * @return list<array<string,mixed>> Liste gemappter Treffer (SupportMatch-ähnlich).
     *
     * @phpstan-return list<SupportMatch>
     * @psalm-return list<SupportMatch>
     */
    private function kbMatch(string $message): array
    {
        return $this->matchSolutions($message);
    }

    /**
     * Minimal-Implementierung: entfernt Blacklist-Tokens aus dem Text.
     * Best practice wäre: eigener Service, der TKFashionPolicyKeywords nutzt.
     */
    private function applyKeywordBlacklist(string $message): string
    {
        // TODO: Langfristig sauber an App\Validator\TKFashionPolicyKeywords anbinden.
        // Quick-Win: Datei laden, Tokens als "Wort"-Blacklist anwenden, ohne Satzzeichen zu zerstören.
        $path = dirname(__DIR__) . '/Service/Prompts/TKFashionPolicyKeywords.config';
        if (!is_file($path)) {
            return $message;
        }

        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            return $message;
        }

        $blacklist = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $blacklist[] = $line;
        }

        if ($blacklist === []) {
            return $message;
        }

        // Ersetze nur ganze Wörter (word boundaries), case-insensitive, Unicode.
        // Wichtig: Wir lassen Interpunktion (z.B. 01.01.2026) intakt.
        $clean = $message;
        foreach ($blacklist as $term) {
            $t = preg_quote($term, '/');
            $clean = preg_replace('/(?<![\p{L}\p{N}_])' . $t . '(?![\p{L}\p{N}_])/iu', ' ', $clean) ?? $clean;
        }

        // Whitespace normalisieren
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        return trim($clean);
    }


    private function extractKeywordTokens(string $message, array $removeWords = []): array
    {
        $m = mb_strtolower($message);

        // rauswerfen: entfernte Steuerwörter (newsletter/form etc.)
        foreach ($removeWords as $w) {
            $w = mb_strtolower((string)$w);
            if ($w === '') {
                continue;
            }
            $m = preg_replace('/(?<![\p{L}\p{N}_])' . preg_quote($w, '/') . '(?![\p{L}\p{N}_])/iu', ' ', $m) ?? $m;
        }

        // Datum-/Zahlenmuster raus (01.01.2026, 2026-01-01, qtl, monate)
        $m = preg_replace('/\b\d{1,2}\.\d{1,2}\.\d{2,4}\b/u', ' ', $m) ?? $m;
        $m = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/u', ' ', $m) ?? $m;
        $m = preg_replace('/\b[1-4]\s*qtl\b/u', ' ', $m) ?? $m;
        $m = preg_replace('/\b[1-4]\s*quartal\b/u', ' ', $m) ?? $m;

        // Tokenisierung
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $m) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        $stop = [
            'in','im','am','an','auf','zu','zum','zur','von','vom','ab','seit','bis','und','oder',
            'der','die','das','des','den','dem','ein','eine','einen','einem','einer',
            'bitte','danke',
        ];

        $out = [];
        foreach ($parts as $p) {
            if (in_array($p, $stop, true)) {
                continue;
            }
            if (mb_strlen($p) < 3) {
                continue;
            }
            // muss Buchstaben enthalten (keine reinen Zahlen)
            if (!preg_match('/\p{L}/u', $p)) {
                continue;
            }
            $out[] = $p;
        }

        $out = array_values(array_unique($out));
        return $out;
    }

    private function parseNewsletterDateRange(string $message): ?array
    {
        $m = mb_strtolower(trim($message));

        // Helpers
        $nowPlus = static fn(string $spec) => (new \DateTimeImmutable('now'))->modify($spec);
        $startOfDay = static fn(\DateTimeImmutable $d) => $d->setTime(0, 0, 0);
        $endOfDay = static fn(\DateTimeImmutable $d) => $d->setTime(23, 59, 59);

        // dd.mm.yy OR dd.mm.yyyy -> DateTimeImmutable
        $parseDotDate = static function (string $dd, string $mm, string $yyOrYyyy): ?\DateTimeImmutable {
            $day = (int)$dd;
            $mon = (int)$mm;
            $yRaw = trim($yyOrYyyy);

            if (strlen($yRaw) === 2) {
                // 00-99 => 2000-2099 (für euren Usecase Newsletter vollkommen ok)
                $year = 2000 + (int)$yRaw;
            } elseif (strlen($yRaw) === 4) {
                $year = (int)$yRaw;
            } else {
                return null;
            }

            $dt = \DateTimeImmutable::createFromFormat('d.m.Y', sprintf('%02d.%02d.%04d', $day, $mon, $year));
            return ($dt instanceof \DateTimeImmutable) ? $dt : null;
        };

        /**
         * 4) Von-Bis / Range: "01.01.2026 - 28.02.2026" / "01.01.26 - 28.02.26" / "... bis ..."
         * dd.mm.(yy|yyyy) - dd.mm.(yy|yyyy)
         */
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\s*(?:\-|–|—|bis)\s*(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b/u', $message, $x)) {
            $from = $parseDotDate($x[1], $x[2], $x[3]);
            $to   = $parseDotDate($x[4], $x[5], $x[6]);

            if ($from instanceof \DateTimeImmutable && $to instanceof \DateTimeImmutable) {
                $from = $startOfDay($from);
                $to   = $endOfDay($to);

                if ($to < $from) {
                    [$from, $to] = [$to, $from];
                }

                return ['from' => $from, 'to' => $to];
            }
        }

        // yyyy-mm-dd - yyyy-mm-dd
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\s*(?:\-|–|—|bis)\s*(\d{4})-(\d{2})-(\d{2})\b/u', $message, $x)) {
            $from = \DateTimeImmutable::createFromFormat('Y-m-d', "{$x[1]}-{$x[2]}-{$x[3]}");
            $to   = \DateTimeImmutable::createFromFormat('Y-m-d', "{$x[4]}-{$x[5]}-{$x[6]}");

            if ($from instanceof \DateTimeImmutable && $to instanceof \DateTimeImmutable) {
                $from = $startOfDay($from);
                $to   = $endOfDay($to);

                if ($to < $from) {
                    [$from, $to] = [$to, $from];
                }

                return ['from' => $from, 'to' => $to];
            }
        }

        /**
         * 3) Quartal: "1Qtl 26" / "1 Qtl 26" / "1 Quartal 26"
         */
        if (preg_match('/\b([1-4])\s*(qtl|quartal)\s*(\d{2})\b/u', $m, $x)) {
            $q = (int)$x[1];
            $yy = (int)$x[3];
            $year = 2000 + $yy;

            $startMonth = ($q - 1) * 3 + 1;

            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $startMonth));
            $to = $from->modify('+3 months')->modify('-1 day');

            return ['from' => $startOfDay($from), 'to' => $endOfDay($to)];
        }

        /**
         * 2) "seit 01.01.2026" oder "ab 01.01.26"
         */
        if (preg_match('/\b(seit|ab)\s+(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b/u', $m, $x)) {
            $from = $parseDotDate($x[2], $x[3], $x[4]);
            if ($from instanceof \DateTimeImmutable) {
                return ['from' => $startOfDay($from), 'to' => $endOfDay($nowPlus('+10 years'))];
            }
        }

        /**
         * 1) "Keyword Newsletter 01.01.2026" (ohne 'seit') => ab Datum bis "weit in Zukunft"
         * dd.mm.(yy|yyyy)
         */
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b/u', $message, $x)) {
            $from = $parseDotDate($x[1], $x[2], $x[3]);
            if ($from instanceof \DateTimeImmutable) {
                return ['from' => $startOfDay($from), 'to' => $endOfDay($nowPlus('+10 years'))];
            }
        }

        // yyyy-mm-dd
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $message, $x)) {
            $from = \DateTimeImmutable::createFromFormat('Y-m-d', "{$x[1]}-{$x[2]}-{$x[3]}");
            if ($from instanceof \DateTimeImmutable) {
                return ['from' => $startOfDay($from), 'to' => $endOfDay($nowPlus('+10 years'))];
            }
        }

        return null;
    }




    private function logNewsletterDiagnostics(
        string $sessionId,
        string $rawMessage,
        array $tokens,
        ?array $range,
        bool $strictPublished,
        array $rows
    ): void {
        $from = $range['from'] ?? null;
        $to   = $range['to'] ?? null;

        $items = [];
        foreach ($rows as $r) {
            $sol = $r['solution'] ?? null;
            if (!$sol instanceof \App\Entity\SupportSolution) {
                continue;
            }

            $publishedAt = null;
            $createdAt = null;

            try {
                $pa = method_exists($sol, 'getPublishedAt') ? $sol->getPublishedAt() : null;
                $ca = method_exists($sol, 'getCreatedAt') ? $sol->getCreatedAt() : null;

                $publishedAt = $pa instanceof \DateTimeInterface ? $pa->format('Y-m-d') : (is_string($pa) ? $pa : null);
                $createdAt   = $ca instanceof \DateTimeInterface ? $ca->format('Y-m-d') : (is_string($ca) ? $ca : null);
            } catch (\Throwable $e) {
                // ignore formatting errors, keep nulls
            }

            $items[] = [
                'id' => method_exists($sol, 'getId') ? $sol->getId() : null,
                'title' => method_exists($sol, 'getTitle') ? $sol->getTitle() : null,
                'type' => method_exists($sol, 'getType') ? $sol->getType() : null,
                'category' => method_exists($sol, 'getCategory') ? $sol->getCategory() : null,
                'newsletterEdition' => method_exists($sol, 'getNewsletterEdition') ? $sol->getNewsletterEdition() : null,
                'publishedAt' => $publishedAt,
                'createdAt' => $createdAt,
            ];
        }

        $this->supportSolutionLogger->debug('newsletter.query_debug', [
            'sessionId' => $sessionId,
            'rawMessage' => $rawMessage,
            'tokens' => $tokens,
            'range' => [
                'from' => $from instanceof \DateTimeImmutable ? $from->format('Y-m-d') : null,
                'to' => $to instanceof \DateTimeImmutable ? $to->format('Y-m-d') : null,
            ],
            'strictPublished' => $strictPublished,
            'rowCount' => count($items),
            'items' => $items,
        ]);
    }


    private function filterMatchesByNewsletterPublishedRange(array $matches, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];

        foreach ($matches as $m) {
            $category = (string)($m['category'] ?? '');

            // Nur Newsletter filtern – SOP/FORM/GENERAL nicht anfassen
            if ($category !== 'NEWSLETTER') {
                $out[] = $m;
                continue;
            }

            // strict: publishedAt MUSS vorhanden sein
            $pubRaw = $m['publishedAt'] ?? null;
            if (!is_string($pubRaw) || trim($pubRaw) === '') {
                continue;
            }

            try {
                $pub = new \DateTimeImmutable($pubRaw);
            } catch (\Throwable $e) {
                // wenn publishedAt nicht parsebar -> raus
                continue;
            }

            if ($pub >= $from && $pub <= $to) {
                $out[] = $m;
            }
        }

        return $out;
    }
    private function filterNewsletterMatchesStrictByPublishedAt(array $matches, \DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        $out = [];

        foreach ($matches as $m) {
            // Nur Newsletter-Einträge filtern
            if (($m['category'] ?? null) !== 'NEWSLETTER') {
                $out[] = $m;
                continue;
            }

            $publishedRaw = $m['publishedAt'] ?? null;
            if (!is_string($publishedRaw) || trim($publishedRaw) === '') {
                // Wenn publishedAt fehlt -> bei Datumsfilter: raus
                continue;
            }

            try {
                $publishedAt = new \DateTimeImmutable($publishedRaw);
            } catch (\Throwable $e) {
                continue;
            }

            if ($publishedAt < $from) {
                continue;
            }
            if ($to instanceof \DateTimeImmutable && $publishedAt > $to) {
                continue;
            }

            $out[] = $m;
        }

        return $out;
    }




}
