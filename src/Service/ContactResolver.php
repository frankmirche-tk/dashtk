<?php

namespace App\Service;

final class ContactResolver
{
    private string $personsFile;
    private string $branchesFile;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $persons = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $branches = null;

    public function __construct(string $projectDir)
    {
        $this->personsFile  = $projectDir . '/var/data/kontakt_personen.json';
        $this->branchesFile = $projectDir . '/var/data/kontakt_filialen.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $query, int $limit = 5): array
    {
        $query = trim($query);
        $qNorm = $this->normalize($query);

        if ($qNorm === '') {
            return [
                'query' => $query,
                'query_norm' => $qNorm,
                'type' => 'none',
                'matches' => [],
            ];
        }

        // 1) Filialcode-Prüfung (COSU/LPGU etc.)
        $branchMatches = $this->matchBranchCode($query, $qNorm);
        if (count($branchMatches) > 0) {
            return [
                'query' => $query,
                'query_norm' => $qNorm,
                'type' => 'branch',
                'matches' => array_slice($branchMatches, 0, $limit),
            ];
        }

        // 2) Personen-Matching (Vorname/Nachname/Firma/Bereich/Notiz)
        $personMatches = $this->matchPersons($query, $qNorm);
        if (count($personMatches) > 0) {
            return [
                'query' => $query,
                'query_norm' => $qNorm,
                'type' => 'person',
                'matches' => array_slice($personMatches, 0, $limit),
            ];
        }

        return [
            'query' => $query,
            'query_norm' => $qNorm,
            'type' => 'none',
            'matches' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchBranchCode(string $queryRaw, string $qNorm): array
    {
        $branches = $this->loadBranches();

        // Candidate (roh) "cosu" -> "COSU"
        $candidate = strtoupper((string)(preg_replace('/\s+/', '', $queryRaw) ?? ''));
        // normalisiert (für Sicherheit bei Sonderzeichen)
        $candidateNorm = $this->normalize($queryRaw);

        $results = [];
        foreach ($branches as $b) {
            $code = strtoupper((string)($b['filialenNr'] ?? ''));
            if ($code === '') {
                continue;
            }

            // exakter Code-Match (primär)
            $isExact = ($candidate === $code);

            // Fallback: normalisiert
            $isNorm = ($candidateNorm !== '' && $candidateNorm === $this->normalize($code));

            if ($isExact || $isNorm) {
                $filialenId = $b['filialenId'] ?? null;

                $results[] = [
                    'id' => (string)($b['id'] ?? ($filialenId ?? $code)),
                    'label' => sprintf(
                        '%s – %s – %s',
                        $filialenId !== null ? (string)$filialenId : '?',
                        (string)($b['filialenNr'] ?? ''),
                        (string)($b['anschrift'] ?? '')
                    ),
                    'confidence' => 0.99,
                    'data' => [
                        'filialenId'    => $b['filialenId'] ?? null,
                        'filialenNr'    => $b['filialenNr'] ?? null,
                        'anschrift'     => $b['anschrift'] ?? null,
                        'strasse'       => $b['strasse'] ?? null,
                        'plz'           => $b['plz'] ?? null,
                        'ort'           => $b['ort'] ?? null,
                        'telefon'       => $b['telefon'] ?? null,
                        'email'         => $b['email'] ?? null,
                        'zusatz'        => $b['zusatz'] ?? null,
                        'gln'           => $b['gln'] ?? null,
                        'ecTerminalId'  => $b['ecTerminalId'] ?? null,
                    ],
                ];
            }
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchPersons(string $queryRaw, string $qNorm): array
    {
        $persons = $this->loadPersons();

        // Tokenisierung: "Frank Müller" => ["frank","mueller"]
        $tokens = array_values(array_filter(preg_split('/\s+/', $qNorm) ?: [], static fn($t) => $t !== ''));

        $results = [];

        foreach ($persons as $p) {
            $first = (string)($p['first_name'] ?? '');
            $last  = (string)($p['last_name'] ?? '');

            $firstNorm = $this->normalize($first);
            $lastNorm  = $this->normalize($last);

            // Zusatzfelder (für Dienstleister/Abteilungen/Use-Cases)
            $departmentNorm = $this->normalize((string)($p['department'] ?? ''));
            $companyNorm    = $this->normalize((string)($p['company'] ?? ''));
            $notesNorm      = $this->normalize((string)($p['notes'] ?? ''));

            $score = 0.0;

            // exakter Nachname
            if ($qNorm === $lastNorm && $lastNorm !== '') {
                $score = max($score, 0.90);
            }

            // exakter Vorname
            if ($qNorm === $firstNorm && $firstNorm !== '') {
                $score = max($score, 0.75);
            }

            // Vorname + Nachname
            if (count($tokens) >= 2) {
                $firstHit = ($firstNorm !== '' && in_array($firstNorm, $tokens, true));
                $lastHit  = ($lastNorm !== '' && in_array($lastNorm, $tokens, true));
                if ($firstHit && $lastHit) {
                    $score = max($score, 0.98);
                }
            }

            // Teilstring-Matching auf Name
            if ($score < 0.90) {
                if ($qNorm !== '' && (
                        ($lastNorm !== '' && str_contains($lastNorm, $qNorm)) ||
                        ($firstNorm !== '' && str_contains($firstNorm, $qNorm))
                    )) {
                    $score = max($score, 0.60);
                }
            }

            // Teilstring-Matching auf Department/Company/Notes (wichtig für Dienstleister/Use-Case-Suche)
            if ($score < 0.90 && $qNorm !== '') {
                if (
                    ($departmentNorm !== '' && str_contains($departmentNorm, $qNorm)) ||
                    ($companyNorm !== '' && str_contains($companyNorm, $qNorm)) ||
                    ($notesNorm !== '' && str_contains($notesNorm, $qNorm))
                ) {
                    $score = max($score, 0.65);
                }
            }

            if ($score > 0.0) {
                $label = trim(sprintf(
                    '%s %s – %s (%s)',
                    (string)($p['first_name'] ?? ''),
                    (string)($p['last_name'] ?? ''),
                    (string)($p['department'] ?? ''),
                    (string)($p['location'] ?? '')
                ));

                $results[] = [
                    'id' => (string)($p['id'] ?? ''),
                    'label' => $label,
                    'confidence' => round($score, 2),
                    'data' => $p,
                ];
            }
        }

        // Sortierung: höchster Score zuerst, dann Label
        usort($results, static function ($a, $b) {
            $c = ($b['confidence'] <=> $a['confidence']);
            if ($c !== 0) {
                return $c;
            }
            return strcmp((string)$a['label'], (string)$b['label']);
        });

        return $results;
    }

    private function normalize(string $s): string
    {
        $s = trim(mb_strtolower($s));

        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ];
        $s = strtr($s, $map);

        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? '';
        $s = preg_replace('/\s+/', ' ', $s) ?? '';

        return trim($s);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPersons(): array
    {
        if ($this->persons !== null) {
            return $this->persons;
        }

        $this->persons = $this->loadJsonFile($this->personsFile);
        return $this->persons;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadBranches(): array
    {
        if ($this->branches !== null) {
            return $this->branches;
        }

        $this->branches = $this->loadJsonFile($this->branchesFile);
        return $this->branches;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }
}
