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
    private const SESSION_TTL_SECONDS = 60 * 60; // 1h
    private const MAX_HISTORY_MESSAGES = 18;     // damit Context nicht zu groß wird

    public function __construct(
        private readonly AIChatRequestHandlerInterface $chatHandler,
        private readonly SupportSolutionRepository $solutions,
        private readonly CacheInterface $cache,
        // ✅ kein Attribute nötig: Symfony kann das anhand des Argumentnamens autowiren
        private readonly LoggerInterface $supportSolutionLogger,
    ) {}

    /**
     * @return array{answer:string,matches:array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}>,modeHint:string}
     */
    public function ask(string $sessionId, string $message, ?int $dbOnlySolutionId = null): array
    {
        $sessionId = trim($sessionId);
        $message   = trim($message);

        if ($sessionId === '') {
            $sessionId = $this->newSessionIdFallback();
        }

        // 1) DB-only Mode: Nur Steps aus DB ausgeben (ohne Gemini)
        if ($dbOnlySolutionId !== null) {
            return $this->answerDbOnly($sessionId, $dbOnlySolutionId);
        }

        // 2) Normal: Matchen + Gemini Antwort erzeugen
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

        // User Message in Verlauf
        $history[] = ['role' => 'user', 'content' => $message];

        // KB Context als Systemmessage (nur intern)
        $kbContext = $this->buildKbContext($matches);

        $this->supportSolutionLogger->info('chat_request', [
            'sessionId' => $sessionId,
            'message' => $message,
            'matchCount' => count($matches),
            'matchIds' => array_map(fn($m) => $m['id'], $matches),
            'kbContextChars' => mb_strlen($kbContext),
        ]);

        // Request bauen
        $builder = $this->chatHandler->createRequest();

        foreach ($this->trimHistory($history) as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = (string) ($msg['content'] ?? '');

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

        $response = $builder->execute();

        $respMsg = $response->getMessage();
        $answer = (is_object($respMsg) && property_exists($respMsg, 'content'))
            ? trim((string) $respMsg->content)
            : '[unlesbare Antwort]';

        // Assistant Antwort in Verlauf
        $history[] = ['role' => 'assistant', 'content' => $answer];
        $this->saveHistory($sessionId, $history);

        $this->supportSolutionLogger->info('chat_response', [
            'sessionId' => $sessionId,
            'answerChars' => mb_strlen($answer),
        ]);

        return [
            'answer' => $answer,
            'matches' => $matches,
            'modeHint' => 'ai_with_db',
        ];
    }

    /**
     * @return array{answer:string,matches:array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}>,modeHint:string}
     */
    private function answerDbOnly(string $sessionId, int $solutionId): array
    {
        $solution = $this->solutions->find($solutionId);

        if (!$solution instanceof SupportSolution) {
            $this->supportSolutionLogger->warning('db_only_not_found', [
                'sessionId' => $sessionId,
                'solutionId' => $solutionId,
            ]);

            return [
                'answer' => 'Die ausgewählte SOP wurde nicht gefunden.',
                'matches' => [],
                'modeHint' => 'db_only',
            ];
        }

        $steps = [];
        foreach ($solution->getSteps() as $st) {
            $steps[] = $st->getStepNo() . ') ' . $st->getInstruction();
        }

        $answer =
            "SOP: {$solution->getTitle()}\n" .
            ($solution->getSymptoms() ? "Symptome: {$solution->getSymptoms()}\n" : '') .
            "\n" .
            ($steps ? implode("\n", $steps) : 'Keine Steps hinterlegt.');

        $matches = [$this->mapMatch($solution, 999)];

        $this->supportSolutionLogger->info('db_only_response', [
            'sessionId' => $sessionId,
            'solutionId' => $solutionId,
            'stepsCount' => count($steps),
        ]);

        return [
            'answer' => $answer,
            'matches' => $matches,
            'modeHint' => 'db_only',
        ];
    }

    /**
     * @return array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}>
     */
    private function findMatches(string $message): array
    {
        if (trim($message) === '') {
            return [];
        }

        $raw = $this->solutions->findBestMatches($message, 5); // <= existiert bei dir schon
        // Erwartet: [['solution'=>SupportSolution,'score'=>int], ...]

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
     * @return array{id:int,title:string,score:int,url:string,stepsUrl:string}
     */
    private function mapMatch(SupportSolution $solution, int $score): array
    {
        $id = (int) $solution->getId();
        $iri = '/api/support_solutions/' . $id;

        // Wenn deine Steps-Resource einen Filter "solution" hat:
        // /api/support_solution_steps?solution=/api/support_solutions/{id}
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
     * Baut einen kompakten KB-Block, den Gemini intern bekommt.
     *
     * @param array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}> $matches
     */
    private function buildKbContext(array $matches): string
    {
        if ($matches === []) {
            return '';
        }

        // Wir geben Gemini kurze SOP-Hinweise + Rule, damit er sie wirklich nutzt
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

        $lines[] =
            'REGEL: Wenn eine SOP passt, führe den User Schritt-für-Schritt. ' .
            'Nach JEDEM Schritt nach dem Ergebnis fragen.';

        return implode("\n", $lines);
    }

    /**
     * @return array<int,array{role:string,content:string}>
     */
    private function loadHistory(string $sessionId): array
    {
        $key = $this->historyCacheKey($sessionId);

        return $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(self::SESSION_TTL_SECONDS);
            return [];
        });
    }

    /**
     * @param array<int,array{role:string,content:string}> $history
     */
    private function saveHistory(string $sessionId, array $history): void
    {
        $key = $this->historyCacheKey($sessionId);

        // overwrite via get() callback
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
        // system msg immer behalten, dann letzten N-1 Messages
        $system = [];
        $rest = $history;

        if ($history !== [] && ($history[0]['role'] ?? null) === 'system') {
            $system = [$history[0]];
            $rest = array_slice($history, 1);
        }

        $rest = array_slice($rest, - (self::MAX_HISTORY_MESSAGES - count($system)));

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
