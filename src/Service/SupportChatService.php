<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Purpose: Orchestrates the support chat flow by combining KB/SOP matching with an AI provider,
 * and persists short-lived conversation history in cache for multi-turn troubleshooting.
 */
final class SupportChatService
{
    private const SESSION_TTL_SECONDS = 3600; // 1h
    private const MAX_HISTORY_MESSAGES = 18;

    public function __construct(
        private readonly AiChatGateway $aiChat,
        private readonly SupportSolutionRepository $solutions,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $supportSolutionLogger,
    ) {}

    /**
     * Main entry point for the support chat.
     *
     * Two operating modes:
     *  - DB-only mode: if $dbOnlySolutionId is provided, returns the selected SOP including its steps payload.
     *  - AI + DB mode: finds best SOP matches for context and calls the AI gateway with trimmed chat history.
     *
     * Conversation state is stored in cache per session (TTL: 1 hour). The system prompt is injected on the
     * first message of a new session to guide the assistant toward step-by-step troubleshooting.
     *
     * @param string      $sessionId         Session identifier used for caching chat history. If empty, a fallback will be generated.
     * @param string      $message           User input message.
     * @param int|null    $dbOnlySolutionId  When set, bypasses AI and returns the SOP content directly.
     * @param string      $provider          AI provider key (normalized to lowercase), e.g. "gemini" or "openai".
     * @param string|null $model             Optional model name/identifier for the chosen provider.
     * @param array       $context           Additional provider-specific context forwarded to the AI gateway.
     *
     * @return array{
     *   answer:string,
     *   matches:array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}>,
     *   modeHint:string,
     *   tts?:string,
     *   mediaUrl?:string,
     *   steps?:array<int,array{
     *     id:string|int|null,
     *     stepNo:int,
     *     instruction:string,
     *     expectedResult?:string|null,
     *     nextIfFailed?:string|null,
     *     mediaPath?:string|null,
     *     mediaUrl?:string|null,
     *     mediaMimeType?:string|null
     *   }>
     * }
     */
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

        if ($sessionId === '') {
            $sessionId = $this->newSessionIdFallback();
        }

        // 1) DB-only Mode
        if ($dbOnlySolutionId !== null) {
            $result = $this->answerDbOnly($sessionId, $dbOnlySolutionId);

            $this->supportSolutionLogger->info('db_only_response', [
                'sessionId'  => $sessionId,
                'solutionId' => $dbOnlySolutionId,
                'stepsCount' => isset($result['steps']) && is_array($result['steps']) ? count($result['steps']) : null,
            ]);

            return $result;
        }

        // 2) Normal: Matchen + KI Antwort
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

        $this->supportSolutionLogger->info('chat_request', [
            'sessionId'      => $sessionId,
            'message'        => $message,
            'matchCount'     => count($matches),
            'matchIds'       => array_map(static fn($m) => $m['id'] ?? null, $matches),
            'kbContextChars' => strlen($kbContext),
            'provider'       => $provider,
            'model'          => $model,
        ]);

        try {
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
     * Returns a response that contains a single SOP (SupportSolution) including its ordered steps.
     *
     * This method does not call the AI provider. It is intended for "show me SOP X" style requests.
     * If the SOP cannot be found, a user-friendly message and an empty steps array is returned.
     *
     * Note: $sessionId is currently unused in this method, but kept in the signature for consistent
     * call structure and potential future tracking/log correlation.
     *
     * @param string $sessionId  Session identifier (currently unused here).
     * @param int    $solutionId Database ID of the SOP to load.
     *
     * @return array{
     *   answer:string,
     *   matches:array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}>,
     *   modeHint:string,
     *   tts:string,
     *   mediaUrl:string,
     *   steps:array<int,array{
     *     id:string|int|null,
     *     stepNo:int,
     *     instruction:string,
     *     expectedResult?:string|null,
     *     nextIfFailed?:string|null,
     *     mediaPath?:string|null,
     *     mediaUrl?:string|null,
     *     mediaMimeType?:string|null
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
     * Finds best matching SOPs for a given user message.
     *
     * Uses the repository full-text/semantic matching (depending on implementation of findBestMatches)
     * and maps results into a lightweight payload for the UI/API.
     *
     * @param string $message User message used as search query.
     *
     * @return array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}>
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
     * Maps a SupportSolution entity plus score into a stable API payload.
     *
     * The payload includes:
     *  - a canonical IRI to the solution
     *  - a prebuilt URL to query the associated steps collection
     *
     * @param SupportSolution $solution The SOP entity.
     * @param int             $score    Relevance score provided by the repository matching layer.
     *
     * @return array{id:int,title:string,score:int,url:string,stepsUrl:string}
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
     * Builds a short knowledge-base context block for the AI prompt from SOP matches.
     *
     * The returned string is meant to be injected into the AI gateway request as "kbContext" so the model
     * can prioritize existing SOPs and follow the "ask after each step" rule.
     *
     * @param array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}> $matches Matched SOPs.
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
     * Loads the chat history for the given session from cache.
     *
     * The cache entry is created lazily with a TTL (SESSION_TTL_SECONDS). If the cache value is not an array,
     * an empty history is returned to keep the calling code safe.
     *
     * @param string $sessionId Session identifier.
     *
     * @return array<int,array{role:string,content:string}> List of chat messages in OpenAI-style format.
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
     * Persists chat history for the given session in cache with the configured TTL.
     *
     * Implementation detail: deletes the key first to ensure the next get() closure writes the new value.
     *
     * @param string                                $sessionId Session identifier.
     * @param array<int,array{role:string,content:string}> $history   Full message list to store.
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
     * Trims the conversation history to a bounded size for provider calls.
     *
     * Keeps the initial "system" message (if present as first item) and then keeps only the last N messages
     * so that total messages do not exceed MAX_HISTORY_MESSAGES.
     *
     * @param array<int,array{role:string,content:string}> $history Full untrimmed history.
     *
     * @return array<int,array{role:string,content:string}> Trimmed history with system message preserved.
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
     * Builds the cache key for a session's chat history.
     *
     * A hash is used to avoid excessively long keys and to reduce the chance of problematic characters.
     *
     * @param string $sessionId Session identifier.
     *
     * @return string Cache key.
     */
    private function historyCacheKey(string $sessionId): string
    {
        return 'support_chat.history.' . sha1($sessionId);
    }

    /**
     * Generates a random fallback session ID if the caller did not provide one.
     *
     * @return string Random session ID (32 hex chars).
     *
     * @throws \Exception If the system CSPRNG fails (random_bytes).
     */
    private function newSessionIdFallback(): string
    {
        return bin2hex(random_bytes(16));
    }
}
