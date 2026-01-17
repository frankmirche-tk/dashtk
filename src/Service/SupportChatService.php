<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class SupportChatService
{
    private const SESSION_TTL_SECONDS = 3600; // 1h
    private const MAX_HISTORY_MESSAGES = 18;

    public function __construct(
        private readonly AIChatRequestHandlerInterface $chatHandler, // Gemini/Modelflow
        private readonly OpenAiChatService $openAiChat,              // OpenAI
        private readonly SupportSolutionRepository $solutions,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $supportSolutionLogger,
    ) {}

    /**
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
        ?string $model = null
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
            if ($provider === 'openai') {
                // ✅ OpenAI Pfad
                $answer = $this->openAiChat->chat(
                    history: $this->trimHistory($history),
                    kbContext: $kbContext,
                    model: $model
                );
            } else {
                // ✅ Gemini/Modelflow Pfad
                $builder = $this->chatHandler->createRequest();

                foreach ($this->trimHistory($history) as $msg) {
                    $role = $msg['role'] ?? 'user';
                    $content = (string)($msg['content'] ?? '');

                    if ($role === 'system') {
                        $builder->addSystemMessage($content);
                    } elseif ($role === 'assistant') {
                        $builder->addAssistantMessage($content);
                    } else {
                        $builder->addUserMessage($content);
                    }
                }

                if ($kbContext !== '') {
                    $builder->addSystemMessage($kbContext);
                }

                // execute()
                $response = $builder->execute();

                $respMsg = $response->getMessage();
                $answer = (is_object($respMsg) && property_exists($respMsg, 'content'))
                    ? trim((string)$respMsg->content)
                    : '[unlesbare Antwort]';
            }
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
            $mapped[] = $this->mapMatch($s, (int)($m['score'] ?? 0));
        }

        $this->supportSolutionLogger->debug('db_matches', [
            'message' => $message,
            'matches' => $mapped,
        ]);

        return $mapped;
    }

    /**
     * @return array{id:int,title:string,score:int,url:string,stepsUrl:string}
     */
    private function mapMatch(SupportSolution $solution, int $score): array
    {
        $id = (int)$solution->getId();
        $iri = '/api/support_solutions/' . $id;
        $stepsUrl = '/api/support_solution_steps?solution=' . rawurlencode($iri);

        return [
            'id' => $id,
            'title' => (string)$solution->getTitle(),
            'score' => $score,
            'url' => $iri,
            'stepsUrl' => $stepsUrl,
        ];
    }

    /**
     * @param array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}> $matches
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
     * @return array<int,array{role:string,content:string}>
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
     * @param array<int,array{role:string,content:string}> $history
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
     * @param array<int,array{role:string,content:string}> $history
     * @return array<int,array{role:string,content:string}>
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

    private function newSessionIdFallback(): string
    {
        return bin2hex(random_bytes(16));
    }
}
