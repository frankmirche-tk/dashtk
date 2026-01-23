<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiChatGateway;
use App\Attribute\TrackUsage;
use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;
use App\Service\ContactResolver; // <-- NEU
use App\Tracing\Trace;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class SupportChatService
{
    private const SESSION_TTL_SECONDS = 3600; // 1h
    private const MAX_HISTORY_MESSAGES = 18;
    private const USAGE_KEY_ASK = 'support_chat.ask';

    public function __construct(
        private readonly AiChatGateway $aiChat,
        private readonly SupportSolutionRepository $solutions,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $supportSolutionLogger,
        private readonly UsageTracker $usageTracker,
        private readonly ContactResolver $contactResolver, // <-- NEU
    ) {}

    private function span(?Trace $trace, string $name, callable $fn, array $meta = [])
    {
        if ($trace) {
            return $trace->span($name, $fn, $meta);
        }
        return $fn();
    }

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

        // BRANCH A: DB-only
        if ($dbOnlySolutionId !== null) {
            return $this->span($trace, 'db_only.answer', function () use ($sessionId, $dbOnlySolutionId) {
                return $this->answerDbOnly($sessionId, $dbOnlySolutionId);
            }, [
                'solution_id' => $dbOnlySolutionId,
            ]);
        }

        /**
         * BRANCH B0 (NEU): Local Contact Resolver (Privacy: local_only, send_to_ai=false)
         *
         * Ziel:
         * - Wenn User nach Kontaktdaten / Filialen / Personen fragt, lösen wir das lokal aus JSON.
         * - Diese Daten dürfen NICHT an externe Provider gehen.
         * - Der Schritt erscheint im Trace als Child-Span unter support_chat.ask.
         */
        $contactHint = $this->span($trace, 'contact.intent.detect', function () use ($message) {
            $m = mb_strtolower($message);

            // einfache Heuristik (pragmatisch, kann später erweitert werden)
            $keywords = [
                'kontakt', 'kontaktperson', 'ansprechpartner', 'telefon', 'tel', 'email', 'e-mail',
                'filiale', 'filialen', 'standort', 'adresse', 'anschrift', 'öffnungszeiten',
                'cosu', 'lpgu', // branch code examples
            ];

            foreach ($keywords as $k) {
                if (str_contains($m, $k)) {
                    return ['hit' => true, 'keyword' => $k];
                }
            }

            // Wenn User nur ein kurzes Token schreibt, lassen wir resolve trotzdem zu (z.B. "COSU")
            $len = mb_strlen(trim($m));
            return ['hit' => ($len > 0 && $len <= 8), 'keyword' => null];
        }, [
            'msg_len' => mb_strlen($message),
        ]);

        $contactResult = $this->span($trace, 'contact.resolve', function () use ($message, $trace) {
            // Resolver bekommt Trace optional -> kann Unterspans schreiben
            return $this->contactResolver->resolve($message, 5, $trace);
        }, [
            'policy' => 'local_only',
            'send_to_ai' => false,
            'source' => 'var/data/kontakt_*.json',
        ]);

        // Wenn intent positiv ODER resolver hat matches -> wir antworten local-only und stoppen AI-Flow
        $hasContactMatches = is_array($contactResult) && !empty($contactResult['matches']);
        $intentHit = (bool)($contactHint['hit'] ?? false);

        if ($intentHit || $hasContactMatches) {
            $answer = $this->span($trace, 'contact.answer.build', function () use ($contactResult) {
                $type = (string)($contactResult['type'] ?? 'none');
                $matches = $contactResult['matches'] ?? [];

                if (!is_array($matches) || $matches === []) {
                    return "Ich habe lokal keine passenden Kontaktdaten gefunden. Bitte nenne mir einen Namen, eine FilialenNr (z.B. COSU) oder einen Standort.";
                }

                $lines = [];
                if ($type === 'branch') {
                    $lines[] = "Gefundene Filiale(n) (lokal):";
                } elseif ($type === 'person') {
                    $lines[] = "Gefundene Kontaktperson(en) (lokal):";
                } else {
                    $lines[] = "Lokale Treffer:";
                }

                foreach ($matches as $m) {
                    $label = (string)($m['label'] ?? '');
                    $conf = isset($m['confidence']) ? (string)$m['confidence'] : '';
                    $lines[] = "- {$label}" . ($conf !== '' ? " (Confidence {$conf})" : '');
                }

                $lines[] = "";
                $lines[] = "Hinweis: Diese Kontaktdaten wurden **lokal** aus JSON ermittelt und **nicht** an einen AI-Provider gesendet.";

                return implode("\n", $lines);
            }, [
                'type' => (string)($contactResult['type'] ?? 'none'),
                'match_count' => is_array($contactResult['matches'] ?? null) ? count($contactResult['matches']) : 0,
            ]);

            return [
                'answer' => $answer,
                'matches' => [],
                'modeHint' => 'contact_local',
                'contact' => $contactResult,
            ];
        }

        // BRANCH B: AI + DB
        $matches = $this->span($trace, 'kb.match', function () use ($message) {
            return $this->findMatches($message);
        }, [
            'query_len' => mb_strlen($message),
        ]);

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
            'kb_chars' => null,
        ]);

        $context = $this->span($trace, 'ai.context_defaults', function () use ($context) {
            $context['usage_key'] ??= self::USAGE_KEY_ASK;
            $context['cache_hit'] ??= false;
            return $context;
        }, [
            'usage_key' => $context['usage_key'] ?? self::USAGE_KEY_ASK,
        ]);

        $model = $this->span($trace, 'ai.model_resolve', function () use ($provider, $model) {
            if ($model === null || trim((string) $model) === '') {
                if ($provider === 'openai') {
                    $model = (string) ($_ENV['OPENAI_DEFAULT_MODEL'] ?? $_SERVER['OPENAI_DEFAULT_MODEL'] ?? '');
                } elseif ($provider === 'gemini') {
                    $model = (string) ($_ENV['GEMINI_DEFAULT_MODEL'] ?? $_SERVER['GEMINI_DEFAULT_MODEL'] ?? '');
                }
                $model = trim((string) $model);
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

        $this->span($trace, 'cache.history_save', function () use ($sessionId, $history) {
            $this->saveHistory($sessionId, $history);
            return null;
        }, [
            'history_count' => count($history),
            'ttl_s' => self::SESSION_TTL_SECONDS,
        ]);

        return [
            'answer' => $answer,
            'matches' => $matches,
            'modeHint' => 'ai_with_db',
        ];
    }


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

        return $mapped;
    }

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

    private function newSessionIdFallback(): string
    {
        return bin2hex(random_bytes(16));
    }
}
