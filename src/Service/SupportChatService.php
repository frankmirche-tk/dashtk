<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Attribute\TrackUsage;
use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * SupportChatService
 *
 * Purpose:
 * - Orchestrates the internal support chat flow by combining:
 *   1) knowledge base / SOP matching (database)
 *   2) AI provider response generation (via AiChatGateway)
 * - Persists short-lived conversation history in cache to support multi-turn troubleshooting.
 *
 * High-level flow:
 * - DB-only mode:
 *   - If a concrete SOP ID is provided, the service returns that SOP (including ordered steps)
 *     without contacting any AI provider.
 * - AI + DB mode:
 *   - Finds relevant SOP matches for the current user message.
 *   - Loads cached conversation history (session-scoped).
 *   - Injects a system prompt on a new session to enforce diagnostic, step-by-step behavior.
 *   - Builds a compact KB context block from matches and forwards it to the AI gateway.
 *   - Saves the updated history back to cache.
 *
 * Conversation history:
 * - Stored per session with a TTL of 1 hour.
 * - Trimmed before provider calls to avoid excessively large prompt payloads.
 *
 * Usage tracking:
 * - ask() is treated as the main service entry point and is tracked via #[TrackUsage]
 *   and an explicit UsageTracker increment.
 *
 * Logging:
 * - Emits structured logs for requests/responses and DB-only results. Intended for
 *   operational debugging and analytics correlation (sessionId, provider/model, match IDs, etc.).
 */
final class SupportChatService
{
    /**
     * Cache TTL for conversation history per session (seconds).
     */
    private const SESSION_TTL_SECONDS = 3600; // 1h

    /**
     * Max number of messages to include when calling an AI provider (including system prompt if present).
     */
    private const MAX_HISTORY_MESSAGES = 18;

    /**
     * Usage tracking key for the main entry point ask().
     */
    private const USAGE_KEY_ASK = 'support_chat.ask';

    /**
     * @param AiChatGateway              $aiChat                Provider gateway that executes the AI chat call.
     * @param SupportSolutionRepository  $solutions             Repository used for SOP retrieval and matching.
     * @param CacheInterface             $cache                 Cache used to store short-lived session history.
     * @param LoggerInterface            $supportSolutionLogger Structured logger channel for chat/SOP diagnostics.
     * @param UsageTracker               $usageTracker          Usage counter for operational reporting.
     */
    public function __construct(
        private readonly AiChatGateway $aiChat,
        private readonly SupportSolutionRepository $solutions,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $supportSolutionLogger,
        private readonly UsageTracker $usageTracker,
    ) {}

    /**
     * Main entry point for the support chat.
     *
     * Operating modes:
     * - DB-only mode:
     *   If $dbOnlySolutionId is provided, this method bypasses the AI provider and returns the selected SOP
     *   including its ordered steps payload.
     * - AI + DB mode:
     *   Finds SOP matches for context and calls the AI gateway using a trimmed history and a KB context block.
     *
     * Session state:
     * - Conversation history is stored in cache per session (TTL: SESSION_TTL_SECONDS).
     * - On a new session (empty history), a system prompt is injected to enforce
     *   step-by-step troubleshooting with follow-up questions after each step.
     *
     * Provider routing:
     * - $provider is normalized to lowercase.
     * - $model can override a provider default model (provider-specific semantics).
     * - $context allows additional structured metadata to be forwarded to the gateway.
     *
     * Error handling:
     * - Provider/gateway exceptions are caught and logged.
     * - A user-friendly error response is returned (with matches preserved) to keep UI behavior stable.
     *
     * @param string      $sessionId         Session identifier used for caching chat history. If empty, a fallback is generated.
     * @param string      $message           User input message.
     * @param int|null    $dbOnlySolutionId  When set, bypasses AI and returns the SOP content directly.
     * @param string      $provider          AI provider key (normalized to lowercase), e.g. "gemini" or "openai".
     * @param string|null $model             Optional model name/identifier for the chosen provider.
     * @param array       $context           Additional provider-specific context forwarded to the AI gateway.
     *
     * @return array{
     *   answer: string,
     *   matches: array<int, array{id:int, title:string, score:int, url:string, stepsUrl:string}>,
     *   modeHint: string,
     *   tts?: string,
     *   mediaUrl?: string,
     *   steps?: array<int, array{
     *     id: string|int|null,
     *     stepNo: int,
     *     instruction: string,
     *     expectedResult?: string|null,
     *     nextIfFailed?: string|null,
     *     mediaPath?: string|null,
     *     mediaUrl?: string|null,
     *     mediaMimeType?: string|null
     *   }>
     * }
     */
    #[TrackUsage(self::USAGE_KEY_ASK, weight: 5)]
    public function ask(
        string $sessionId,
        string $message,
        ?int $dbOnlySolutionId = null,
        string $provider = 'gemini',
        ?string $model = null,
        array $context = []
    ): array {
        $sessionId = trim($sessionId);
        $message   = trim($message);
        $provider  = strtolower(trim($provider));

        // Explicit tracking call (required by policy / linting)
        $this->usageTracker->increment(self::USAGE_KEY_ASK);

        if ($sessionId === '') {
            $sessionId = $this->newSessionIdFallback();
        }

        // 1) DB-only Mode (no AI call)
        if ($dbOnlySolutionId !== null) {
            $result = $this->answerDbOnly($sessionId, $dbOnlySolutionId);

            $this->supportSolutionLogger->info('db_only_response', [
                'sessionId'  => $sessionId,
                'solutionId' => $dbOnlySolutionId,
                'stepsCount' => isset($result['steps']) && is_array($result['steps']) ? count($result['steps']) : null,
            ]);

            return $result;
        }

        // 2) AI + DB Mode: match + provider answer
        $matches = $this->findMatches($message);

        $history = $this->loadHistory($sessionId);
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

        $history[] = ['role' => 'user', 'content' => $message];

        $kbContext = $this->buildKbContext($matches);

        // AI cost attribution (Variant B):
        $context['usage_key'] ??= self::USAGE_KEY_ASK;
        $context['cache_hit'] ??= false;

        // Ensure stable model names for reporting.
        // If caller did not pass a model, fall back to provider defaults configured via env.
        if ($model === null || trim((string) $model) === '') {
            if ($provider === 'openai') {
                $model = (string) ($_ENV['OPENAI_DEFAULT_MODEL'] ?? $_SERVER['OPENAI_DEFAULT_MODEL'] ?? '');
            } elseif ($provider === 'gemini') {
                $model = (string) ($_ENV['GEMINI_DEFAULT_MODEL'] ?? $_SERVER['GEMINI_DEFAULT_MODEL'] ?? '');
            }

            $model = trim((string) $model);
            $model = $model !== '' ? $model : null;
        }

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


        try {
            // AI cost attribution (Variant B):
            // - We reuse the same business usage key as UsageTracker/TrackUsage so reports can join:
            //   usage/impact  <->  tokens/requests/costs
            // - Caller-provided context stays intact; we only enforce the missing key(s).
            $context['usage_key'] ??= self::USAGE_KEY_ASK;

            // Optional: if you ever add an application-side cache for AI answers,
            // set this to true on cache hits to separate "no provider call" cases.
            $context['cache_hit'] ??= false;

            $this->supportSolutionLogger->info('ai_cost_debug', [
                'usage_key' => $context['usage_key'] ?? self::USAGE_KEY_ASK,
                'provider'  => $provider,
                'model'     => $model,
            ]);

            $answer = $this->aiChat->chat(
                history: $this->trimHistory($history),
                kbContext: $kbContext,
                provider: $provider,
                model: $model,
                context: $context
            );
        } catch (\Throwable $e) {

            $this->supportSolutionLogger->error('chat_execute_failed', [
                'sessionId' => $sessionId,
                'message'   => $message,
                'error'     => $e->getMessage(),
                'class'     => $e::class,
                'provider'  => $provider,
                'model'     => $model,
            ]);

            return [
                'answer' => 'Fehler beim Erzeugen der Antwort. Bitte Logs prüfen.',
                'matches' => $matches,
                'modeHint' => 'ai_with_db',
            ];
        }

        $history[] = ['role' => 'assistant', 'content' => $answer];
        $this->saveHistory($sessionId, $history);

        $this->supportSolutionLogger->info('chat_response', [
            'sessionId'   => $sessionId,
            'answerChars' => strlen($answer),
            'provider'    => $provider,
            'model'       => $model,
        ]);

        return [
            'answer' => $answer,
            'matches' => $matches,
            'modeHint' => 'ai_with_db',
        ];
    }

    /**
     * Returns a DB-only response containing a single SOP (SupportSolution) including its ordered steps.
     *
     * Characteristics:
     * - Does not call the AI provider.
     * - Intended for "show me SOP X" / UI-driven SOP selection flows.
     * - If no SOP is found for the given ID, a user-friendly message and an empty steps array is returned.
     *
     * Note:
     * - $sessionId is currently unused in this method, but kept for consistent call structure and potential
     *   future tracking/log correlation.
     *
     * @param string $sessionId  Session identifier (currently unused here).
     * @param int    $solutionId Database ID of the SOP to load.
     *
     * @return array{
     *   answer: string,
     *   matches: array<int, array{id:int, title:string, score:int, url:string, stepsUrl:string}>,
     *   modeHint: string,
     *   tts: string,
     *   mediaUrl: string,
     *   steps: array<int, array{
     *     id: string|int|null,
     *     stepNo: int,
     *     instruction: string,
     *     expectedResult?: string|null,
     *     nextIfFailed?: string|null,
     *     mediaPath?: string|null,
     *     mediaUrl?: string|null,
     *     mediaMimeType?: string|null
     *   }>
     * }
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
                'stepNo' => (int) $st->getStepNo(),
                'instruction' => (string) $st->getInstruction(),
                'expectedResult' => $st->getExpectedResult(),
                'nextIfFailed' => $st->getNextIfFailed(),
                'mediaPath' => $st->getMediaPath(),
                'mediaUrl' => method_exists($st, 'getMediaUrl') ? $st->getMediaUrl() : null,
                'mediaMimeType' => $st->getMediaMimeType(),
            ];
        }

        // Build a human-readable multi-line answer (also useful for TTS or quick copy/paste)
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
     * Find best matching SOPs for a given user message.
     *
     * Uses the repository matching implementation (full-text / semantic, depending on repository),
     * then maps the results into a stable, UI-friendly payload.
     *
     * @param string $message User message used as search query.
     *
     * @return array<int, array{id:int, title:string, score:int, url:string, stepsUrl:string}>
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
            $mapped[] = $this->mapMatch($s, (int) ($m['score'] ?? 0));
        }

        $this->supportSolutionLogger->debug('db_matches', [
            'message' => $message,
            'matches' => $mapped,
        ]);

        return $mapped;
    }

    /**
     * Map a SupportSolution entity plus score into a stable API payload.
     *
     * The payload includes:
     * - canonical IRI to the solution resource
     * - prebuilt URL to query associated steps collection
     *
     * @param SupportSolution $solution SOP entity.
     * @param int             $score    Relevance score from repository matching layer.
     *
     * @return array{id:int, title:string, score:int, url:string, stepsUrl:string}
     */
    private function mapMatch(SupportSolution $solution, int $score): array
    {
        $id = (int) $solution->getId();
        $iri = '/api/support_solutions/' . $id;
        $stepsUrl = '/api/support_solution_steps?solution=' . rawurlencode($iri);

        return [
            'id' => $id,
            'title' => (string) $solution->getTitle(),
            'score' => $score,
            'url' => $iri,
            'stepsUrl' => $stepsUrl,
        ];
    }

    /**
     * Build a short knowledge-base context block for the AI prompt from SOP matches.
     *
     * The returned string is injected into provider requests via AiChatGateway as "kbContext"
     * so the model can prioritize SOPs and follow the "step-by-step with follow-up" rule.
     *
     * @param array<int, array{id:int, title:string, score:int, url:string, stepsUrl:string}> $matches
     *
     * @return string Multi-line context or empty string if no matches exist.
     */
    private function buildKbContext(array $matches): string
    {
        if ($matches === []) {
            return '';
        }

        $lines = [];
        $lines[] = 'WISSENSDATENBANK (SOPs) – nutze diese vorrangig, wenn passend:';
        foreach ($matches as $hit) {
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
     * Load the chat history for the given session from cache.
     *
     * Cache behavior:
     * - Creates the cache entry lazily with TTL SESSION_TTL_SECONDS.
     * - Returns an empty array if the cache value is missing or not an array.
     *
     * @param string $sessionId Session identifier.
     *
     * @return array<int, array{role:string, content:string}> Chat message list in OpenAI-style format.
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
     * Persist chat history for the given session in cache with TTL SESSION_TTL_SECONDS.
     *
     * Implementation detail:
     * - Deletes the key first, then writes via get() callback.
     * - This pattern forces refresh and ensures consistent TTL handling.
     *
     * @param string                                $sessionId Session identifier.
     * @param array<int, array{role:string, content:string}> $history   Full message list to store.
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
     * Trim the conversation history to a bounded size for provider calls.
     *
     * Rules:
     * - If the first message is a "system" message, it is preserved.
     * - The remaining messages are truncated from the front so that the total message count
     *   does not exceed MAX_HISTORY_MESSAGES.
     *
     * @param array<int, array{role:string, content:string}> $history Full untrimmed history.
     *
     * @return array<int, array{role:string, content:string}> Trimmed history.
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
     * Build the cache key for a session's chat history.
     *
     * A SHA1 hash is used to:
     * - avoid excessively long cache keys
     * - prevent problematic characters from session IDs in cache backend implementations
     *
     * @param string $sessionId Session identifier.
     *
     * @return string Cache key used for history persistence.
     */
    private function historyCacheKey(string $sessionId): string
    {
        return 'support_chat.history.' . sha1($sessionId);
    }

    /**
     * Generate a random fallback session ID if the caller did not provide one.
     *
     * @return string Random session ID (32 hex chars).
     *
     * @throws \Exception If random_bytes() fails.
     */
    private function newSessionIdFallback(): string
    {
        return bin2hex(random_bytes(16));
    }
}
