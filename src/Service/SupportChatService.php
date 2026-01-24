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
        KernelInterface $kernel,
    ) {
        $this->isDev = $kernel->getEnvironment() === 'dev';
    }

    private function span(?Trace $trace, string $name, callable $fn, array $meta = []): mixed
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

        /**
         * BRANCH B1a: If user likely asks for a FORM/document, answer DB-only with a selection list
         * (no AI call necessary).
         */
        $formIntent = $this->span($trace, 'form.intent.detect', function () use ($message, $matches) {
            $m = mb_strtolower($message);
            $keywords = ['formular', 'form', 'dokument', 'pdf', 'antrag', 'vorlage'];
            foreach ($keywords as $k) {
                if (str_contains($m, $k)) {
                    return true;
                }
            }

            // if we already matched a FORM with good score
            foreach ($matches as $hit) {
                if (($hit['type'] ?? null) === 'FORM' && (int)($hit['score'] ?? 0) >= 6) {
                    return true;
                }
            }

            return false;
        });

        if ($formIntent) {
            $formAnswer = $this->span($trace, 'form.answer.build', function () use ($matches) {
                $forms = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) === 'FORM'));
                $sops  = array_values(array_filter($matches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));

                if ($forms === []) {
                    return [
                        'answer' => "Ich habe kein passendes Formular gefunden. Nenne mir bitte den genauen Namen (z.B. „Reisekosten Antrag“) oder ergänze 1–2 Stichwörter.",
                        'choices' => [],
                        'matches' => $matches,
                    ];
                }

                $lines = [];
                $lines[] = "Ich habe passende **Formulare** gefunden:";
                $choices = [];

                $i = 1;
                foreach ($forms as $f) {
                    $title = (string)($f['title'] ?? '');
                    $updated = (string)($f['updatedAt'] ?? '');
                    $lines[] = "{$i}) {$title}" . ($updated !== '' ? " (zuletzt aktualisiert: {$updated})" : '');
                    $choices[] = [
                        'kind' => 'form',
                        'label' => $title,
                        'payload' => $f,
                    ];
                    $i++;
                    if ($i > self::MAX_CHOICES) {
                        break;
                    }
                }

                if ($sops !== []) {
                    $lines[] = "";
                    $lines[] = "Zusätzlich gibt es passende SOPs:";
                    foreach (array_slice($sops, 0, 3) as $s) {
                        $lines[] = "- " . (string)($s['title'] ?? '');
                    }
                }

                $lines[] = "";
                $lines[] = "Antworte mit **1–" . count($choices) . "**, um ein Formular zu öffnen.";

                return [
                    'answer' => implode("\n", $lines),
                    'choices' => $choices,
                    'matches' => $matches,
                ];
            });

            if (!empty($formAnswer['choices'])) {
                $this->storeChoices($sessionId, $formAnswer['choices']);
            }

            return [
                'answer' => $formAnswer['answer'] ?? '',
                'matches' => $formAnswer['matches'] ?? $matches,
                'modeHint' => 'form_db',
                'choices' => $formAnswer['choices'] ?? [],
            ];
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

            return [
                'answer' => $msg,
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

        // Optional: store KB choices for numeric selection (SOP-only)
        $kbChoices = $this->buildKbChoices($matches);
        if ($kbChoices !== []) {
            $this->storeChoices($sessionId, $kbChoices);
        }

        return [
            'answer' => $answer,
            'matches' => $matches,
            'modeHint' => 'ai_with_db',
            'choices' => $kbChoices,
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
     * Returns NULL if not a numeric selection or no stored choices exist.
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
            $url = (string)($payload['externalMediaUrl'] ?? $payload['url'] ?? '');
            $updated = (string)($payload['updatedAt'] ?? '');
            $lines = [];
            $lines[] = "Formular: {$label}";
            if ($updated !== '') {
                $lines[] = "Zuletzt aktualisiert: {$updated}";
            }
            if ($url !== '') {
                $lines[] = "Link: {$url}";
            }
            $lines[] = "";
            $lines[] = "Möchtest du ein anderes Formular aus der Liste öffnen (z.B. „2“) oder suchst du etwas anderes?";

            return [
                'answer' => implode("\n", $lines),
                'matches' => [],
                'modeHint' => 'choice_form',
                'selected' => $choice,
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
}
