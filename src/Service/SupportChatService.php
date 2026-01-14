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
    private const MAX_HISTORY_MESSAGES = 18;

    public function __construct(
        private readonly AIChatRequestHandlerInterface $chatHandler,
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
     *   mediaUrl?:string
     * }
     */
    public function ask(string $sessionId, string $message, ?int $dbOnlySolutionId = null): array
    {
        $sessionId = trim($sessionId);
        $message   = trim($message);

        if ($sessionId === '') {
            $sessionId = $this->newSessionIdFallback();
        }

        // 1) DB-only Mode: Nur Steps aus DB
        if ($dbOnlySolutionId !== null) {
            return $this->answerDbOnly($sessionId, $dbOnlySolutionId);
        }

        // 2) Normal: Matchen + KI Antwort (OHNE Avatar-Felder!)
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

        $history[] = ['role' => 'assistant', 'content' => $answer];
        $this->saveHistory($sessionId, $history);

        return [
            'answer' => $answer,
            'matches' => $matches,
            'modeHint' => 'ai_with_db',
        ];
    }

    /**
     * DB-only Antwort + Avatar-Vorschlag (tts/mediaUrl)
     *
     * @return array{
     *   answer:string,
     *   matches:array<int,array{id:int,title:string,score:int,url:string,stepsUrl:string}>,
     *   modeHint:string,
     *   tts:string,
     *   mediaUrl:string
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

        return [
            'answer' => $answer,

            // ✅ WICHTIG: damit die SOP-Box NICHT doppelt erscheint
            'matches' => [],

            'modeHint' => 'db_only',

            // ✅ Avatar nur im DB-only Mode anbieten
            'tts' => 'Hallo, ich zeige dir jetzt wie du die Aufträge löschst.',
            'mediaUrl' => '/guides/print/step1.gif',
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

        $raw = $this->solutions->findBestMatches($message, 5);

        $mapped = [];
        foreach ($raw as $m) {
            $s = $m['solution'] ?? null;
            if (!$s instanceof SupportSolution) {
                continue;
            }
            $mapped[] = $this->mapMatch($s, (int) ($m['score'] ?? 0));
        }

        return $mapped;
    }

    /**
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
