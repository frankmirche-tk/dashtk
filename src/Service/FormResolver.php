<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SupportSolution;
use App\Repository\SupportSolutionRepository;

final class FormResolver
{
    private const MAX_CHOICES = 8;

    public function __construct(
        private readonly SupportSolutionRepository $solutions,
    ) {}

    /**
     * Resolver für den Chat-Flow:
     * - Prüft Form-Intent
     * - Nutzt vorhandene KB-Matches (schnell) oder DB-Suche fallback
     * - Liefert Payload zurück, das direkt als ChatController-Response genutzt werden kann
     *
     * @param array<int, array<string, mixed>> $kbMatches Matches aus SupportChatService::findMatches()
     *
     * @return array<string,mixed>|null  Null => nicht zuständig, AI/normaler Flow soll weiterlaufen.
     */
    public function resolveFromDb(string $message, array $kbMatches = []): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        // 1) Intent check
        if (!$this->detectFormIntent($message, $kbMatches)) {
            return null;
        }

        // 2) Forms + Sops aus den vorhandenen KB-Matches ziehen (schnell)
        $forms = array_values(array_filter($kbMatches, static fn(array $m) => ($m['type'] ?? null) === 'FORM'));
        $sops  = array_values(array_filter($kbMatches, static fn(array $m) => ($m['type'] ?? null) !== 'FORM'));

        // 3) Fallback: wenn KB zwar Intent sagt, aber keine Forms geliefert hat, DB-Suche
        if ($forms === []) {
            $forms = $this->findFormsFallback($message);
        }

        // 4) Antwort + Choices bauen
        return $this->buildFormSelectionPayload($forms, $sops);
    }

    /**
     * Wird genutzt, um aus Provider/Id eine Preview-URL zu bauen.
     * Damit funktionieren auch Fälle, wo externalMediaUrl leer ist.
     */
    public function buildPreviewUrl(?string $provider, ?string $externalUrl, ?string $externalId): ?string
    {
        $externalUrl = trim((string)$externalUrl);
        if ($externalUrl !== '') {
            return $externalUrl;
        }

        $externalId = trim((string)$externalId);
        if ($externalId === '') {
            return null;
        }

        $p = mb_strtolower(trim((string)$provider));

        // Google Drive File Preview
        if ($p === 'google_drive' || $p === 'gdrive' || $p === 'drive') {
            return 'https://drive.google.com/file/d/' . rawurlencode($externalId) . '/view';
        }

        // Default: wenn unknown provider, aber ID vorhanden -> keine sichere URL ableitbar
        return null;
    }

    /**
     * Intent detection:
     * - Keyword Heuristik
     * - oder KB liefert bereits FORM Treffer mit brauchbarem Score
     *
     * @param array<int, array<string,mixed>> $matches
     */
    private function detectFormIntent(string $message, array $matches): bool
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') {
            return false;
        }

        $keywords = ['formular', 'form', 'dokument', 'pdf', 'antrag', 'vorlage'];
        foreach ($keywords as $k) {
            if (str_contains($m, $k)) {
                return true;
            }
        }

        foreach ($matches as $hit) {
            if (($hit['type'] ?? null) === 'FORM' && (int)($hit['score'] ?? 0) >= 6) {
                return true;
            }
        }

        return false;
    }

    /**
     * DB fallback: best matches holen und auf FORM filtern.
     *
     * @return array<int, array<string,mixed>>  FORM-mapped wie SupportChatService::mapMatch
     */
    private function findFormsFallback(string $message): array
    {
        $raw = $this->solutions->findBestMatches($message, 10);

        $forms = [];
        foreach ($raw as $m) {
            $s = $m['solution'] ?? null;
            if (!$s instanceof SupportSolution) {
                continue;
            }

            if ($s->getType() !== 'FORM') {
                continue;
            }

            $forms[] = $this->mapFormMatch($s, (int)($m['score'] ?? 0));
        }

        return $forms;
    }

    /**
     * Mappt DB-Form so, dass es als Choice-Payload taugt.
     */
    private function mapFormMatch(SupportSolution $solution, int $score): array
    {
        $id = (int)$solution->getId();
        $iri = '/api/support_solutions/' . $id;

        return [
            'id' => $id,
            'title' => (string)$solution->getTitle(),
            'score' => $score,
            'url' => $iri,
            'type' => 'FORM',
            'updatedAt' => $solution->getUpdatedAt()->format('Y-m-d H:i'),

            'mediaType' => $solution->getMediaType(),
            'externalMediaProvider' => $solution->getExternalMediaProvider(),
            'externalMediaUrl' => $solution->getExternalMediaUrl(),
            'externalMediaId' => $solution->getExternalMediaId(),
        ];
    }

    /**
     * Baut die Chat-Response für "Formulare gefunden" + Choices.
     *
     * @param array<int, array<string,mixed>> $forms
     * @param array<int, array<string,mixed>> $sops
     *
     * @return array{answer:string, choices:array<int,array{kind:string,label:string,payload:array}>, matches:array<int,array<string,mixed>>, modeHint:string}
     */
    private function buildFormSelectionPayload(array $forms, array $sops): array
    {
        if ($forms === []) {
            return [
                'answer' => "Ich habe kein passendes Formular gefunden. Nenne mir bitte den genauen Namen (z.B. „Reisekosten Antrag“) oder ergänze 1–2 Stichwörter.",
                'choices' => [],
                'matches' => $sops, // SOPs dürfen trotzdem angezeigt werden
                'modeHint' => 'form_db_empty',
            ];
        }

        // Dedupe (Titel + ID/URL)
        $seen = [];
        $unique = [];
        foreach ($forms as $f) {
            $key = (string)($f['externalMediaUrl'] ?? '') . '|' . (string)($f['externalMediaId'] ?? '') . '|' . (string)($f['title'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $f;
        }

        $unique = array_slice($unique, 0, self::MAX_CHOICES);

        $lines = [];
        $lines[] = 'Ich habe passende **Formulare** gefunden:';

        $choices = [];
        $i = 1;

        foreach ($unique as $f) {
            $title = (string)($f['title'] ?? '');
            $updated = (string)($f['updatedAt'] ?? '');
            if ($title === '') continue;

            $lines[] = "{$i}) {$title}" . ($updated !== '' ? " (zuletzt aktualisiert: {$updated})" : '');
            $choices[] = [
                'kind' => 'form',
                'label' => $title,
                'payload' => $f,
            ];
            $i++;
        }

        $lines[] = '';
        $lines[] = 'Antworte mit **1–' . count($choices) . '**, um ein Formular zu öffnen.';

        return [
            'answer' => implode("\n", $lines),
            'choices' => $choices,
            // ✅ matches nur SOPs – UI zeigt SOP-Box korrekt
            'matches' => $sops,
            'modeHint' => 'form_db',
        ];
    }

    public function hasFormKeywords(string $message): bool
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') return false;

        foreach (['pdf','form','formular','dokument','antrag','vorlage'] as $k) {
            if (str_contains($m, $k)) return true;
        }
        return false;
    }

}
