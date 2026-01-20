<?php

namespace App\Service;

use App\Attribute\TrackUsage;

/**
 * Resolves internal contact and branch information from local JSON files.
 *
 * Purpose / Privacy intent:
 * - This resolver is explicitly designed to keep contact/branch lookup local.
 * - Data from these JSON files must NOT be sent to external AI providers.
 * - The result can be used by higher-level services (e.g. SupportChatService) to
 *   respond with verified contact details without leaking personal data.
 *
 * Data sources:
 * - var/data/kontakt_personen.json
 * - var/data/kontakt_filialen.json
 *
 * Matching strategy (high level):
 * 1) Branch code match (exact / normalized), e.g. "COSU", "LPGU"
 * 2) Person match by name tokens and additionally by department/company/notes
 *
 * Usage tracking:
 * - Marked as a tracked entry point via #[TrackUsage]
 * - Explicitly increments UsageTracker for deterministic reporting / linting
 */
final class ContactResolver
{
    /**
     * Absolute path to the local JSON file containing persons.
     */
    private string $personsFile;

    /**
     * Absolute path to the local JSON file containing branches/filialen.
     */
    private string $branchesFile;

    /**
     * Usage tracking key for this resolver entry point.
     */
    private const USAGE_KEY_RESOLVE = 'contact_resolver.resolve';

    /**
     * In-memory cache of decoded persons JSON.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $persons = null;

    /**
     * In-memory cache of decoded branches JSON.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $branches = null;

    /**
     * @param string       $projectDir    Symfony project root directory (kernel.project_dir).
     * @param UsageTracker $usageTracker  Usage tracker for deterministic counting/reporting.
     */
    public function __construct(string $projectDir, private readonly UsageTracker $usageTracker)
    {
        $this->personsFile  = $projectDir . '/var/data/kontakt_personen.json';
        $this->branchesFile = $projectDir . '/var/data/kontakt_filialen.json';
    }

    /**
     * Resolve a query to either a branch match, a person match, or "none".
     *
     * Return format:
     * - type = "branch": matches contains branch entries
     * - type = "person": matches contains person entries
     * - type = "none":   matches is empty
     *
     * Each match includes:
     * - id: stable identifier (string)
     * - label: human readable output
     * - confidence: float 0..1 (rounded to 2 decimals for persons)
     * - data: original record payload (array)
     *
     * Notes:
     * - This method is a "business entry point" for local contact lookup and is tracked.
     * - $limit is applied after ranking/sorting (persons) or after match collection (branches).
     *
     * @param string $query Raw user query (may contain casing/umlauts/punctuation).
     * @param int    $limit Maximum number of matches to return.
     *
     * @return array{
     *   query: string,
     *   query_norm: string,
     *   type: 'branch'|'person'|'none',
     *   matches: array<int, array<string, mixed>>
     * }
     */
    #[TrackUsage(self::USAGE_KEY_RESOLVE, weight: 3)]
    public function resolve(string $query, int $limit = 5): array
    {
        // Explicit tracking call (required by policy / linting)
        $this->usageTracker->increment(self::USAGE_KEY_RESOLVE);

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

        // 1) Branch code check (COSU/LPGU etc.)
        $branchMatches = $this->matchBranchCode($query, $qNorm);
        if (count($branchMatches) > 0) {
            return [
                'query' => $query,
                'query_norm' => $qNorm,
                'type' => 'branch',
                'matches' => array_slice($branchMatches, 0, $limit),
            ];
        }

        // 2) Person matching (first/last name, company/department/notes)
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
     * Match branch codes (filialenNr) against the raw and normalized query.
     *
     * @param string $queryRaw Raw user query.
     * @param string $qNorm    Normalized query (already computed).
     *
     * @return array<int, array<string, mixed>> List of branch matches.
     */
    private function matchBranchCode(string $queryRaw, string $qNorm): array
    {
        $branches = $this->loadBranches();

        // Candidate (raw) "cosu" -> "COSU"
        $candidate = strtoupper((string)(preg_replace('/\s+/', '', $queryRaw) ?? ''));
        // Normalized (defensive) to handle special chars
        $candidateNorm = $this->normalize($queryRaw);

        $results = [];
        foreach ($branches as $b) {
            $code = strtoupper((string)($b['filialenNr'] ?? ''));
            if ($code === '') {
                continue;
            }

            // Primary: exact code match
            $isExact = ($candidate === $code);

            // Fallback: normalized comparison
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
     * Match persons by name tokens and additional fields (department/company/notes).
     *
     * Scoring (rough guideline):
     * - 0.98: first + last name token hit
     * - 0.90: exact last name
     * - 0.75: exact first name
     * - 0.65: department/company/notes contains query
     * - 0.60: partial match in name
     *
     * @param string $queryRaw Raw query (currently only used for symmetry/debugging).
     * @param string $qNorm    Normalized query.
     *
     * @return array<int, array<string, mixed>> Ranked list of person matches.
     */
    private function matchPersons(string $queryRaw, string $qNorm): array
    {
        $persons = $this->loadPersons();

        // Tokenization: "Frank Müller" => ["frank","mueller"]
        $tokens = array_values(array_filter(
            preg_split('/\s+/', $qNorm) ?: [],
            static fn($t) => $t !== ''
        ));

        $results = [];

        foreach ($persons as $p) {
            $first = (string)($p['first_name'] ?? '');
            $last  = (string)($p['last_name'] ?? '');

            $firstNorm = $this->normalize($first);
            $lastNorm  = $this->normalize($last);

            $departmentNorm = $this->normalize((string)($p['department'] ?? ''));
            $companyNorm    = $this->normalize((string)($p['company'] ?? ''));
            $notesNorm      = $this->normalize((string)($p['notes'] ?? ''));

            $score = 0.0;

            if ($qNorm === $lastNorm && $lastNorm !== '') {
                $score = max($score, 0.90);
            }

            if ($qNorm === $firstNorm && $firstNorm !== '') {
                $score = max($score, 0.75);
            }

            if (count($tokens) >= 2) {
                $firstHit = ($firstNorm !== '' && in_array($firstNorm, $tokens, true));
                $lastHit  = ($lastNorm !== '' && in_array($lastNorm, $tokens, true));
                if ($firstHit && $lastHit) {
                    $score = max($score, 0.98);
                }
            }

            if ($score < 0.90) {
                if ($qNorm !== '' && (
                        ($lastNorm !== '' && str_contains($lastNorm, $qNorm)) ||
                        ($firstNorm !== '' && str_contains($firstNorm, $qNorm))
                    )) {
                    $score = max($score, 0.60);
                }
            }

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

        // Sort: highest confidence first, then label
        usort($results, static function ($a, $b) {
            $c = ($b['confidence'] <=> $a['confidence']);
            if ($c !== 0) {
                return $c;
            }
            return strcmp((string)$a['label'], (string)$b['label']);
        });

        return $results;
    }

    /**
     * Normalize user input and source strings for robust matching:
     * - lowercase
     * - german umlaut mapping (ä->ae, ö->oe, ü->ue, ß->ss)
     * - remove punctuation/special chars
     * - collapse whitespace
     */
    private function normalize(string $s): string
    {
        $s = trim(mb_strtolower($s));

        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $s = strtr($s, $map);

        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? '';
        $s = preg_replace('/\s+/', ' ', $s) ?? '';

        return trim($s);
    }

    /**
     * Lazy-load persons from JSON into memory.
     *
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
     * Lazy-load branches from JSON into memory.
     *
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
     * Read and decode a JSON file into a list of associative arrays.
     *
     * Defensive behavior:
     * - returns [] if file does not exist, cannot be read, or is invalid JSON
     * - filters non-array elements to keep the structure predictable
     *
     * @param string $path Absolute file path.
     *
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
