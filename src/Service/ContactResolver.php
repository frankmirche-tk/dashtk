<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\TrackUsage;
use App\Tracing\Trace;


/**
 * Resolves internal contact and branch information from local JSON files.
 *
 * Privacy / policy:
 * - This resolver is explicitly designed to keep contact/branch lookup local.
 * - Data from these JSON files must NOT be sent to external AI providers.
 * - Higher-level services can use the result to respond with verified contact details
 *   without leaking personal data.
 *
 * Data sources:
 * - var/data/kontakt_personen.json
 * - var/data/kontakt_filialen.json
 *
 * Matching strategy (high level):
 * 1) Branch code match (exact / normalized), e.g. "COSU", "LPGU"
 * 2) Person match by name tokens and additional fields (department/company/notes)
 */
final class ContactResolver
{
    /**
     * Usage tracking key for this resolver entry point.
     */
    public const USAGE_KEY_RESOLVE = 'contact_resolver.resolve';

    /**
     * Absolute path to the local JSON file containing persons.
     */
    private string $personsFile;

    /**
     * Absolute path to the local JSON file containing branches/filialen.
     */
    private string $branchesFile;

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

    public function __construct(
        string $projectDir,
        private readonly UsageTracker $usageTracker
    ) {
        $this->personsFile  = rtrim($projectDir, '/') . '/var/data/kontakt_personen.json';
        $this->branchesFile = rtrim($projectDir, '/') . '/var/data/kontakt_filialen.json';
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
     * @param string     $query Raw user query (may contain casing/umlauts/punctuation).
     * @param int        $limit Maximum number of matches to return (applied after ranking/slicing).
     * @param Trace|null $trace Optional trace for UI tree/debugging.
     *
     * @return array{
     *   query: string,
     *   query_norm: string,
     *   type: 'branch'|'person'|'none',
     *   matches: array<int, array{
     *     id: string,
     *     label: string,
     *     confidence: float,
     *     data: array<string, mixed>
     *   }>
     * }
     */
    #[TrackUsage(self::USAGE_KEY_RESOLVE, weight: 3)]
    public function resolve(string $query, int $limit = 5, ?Trace $trace = null): array
    {
        // deterministic usage tracking (policy/linting requirement)
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

        // Preload JSON with spans (so "why fast/slow" is visible in trace tree)
        if ($trace) {
            $trace->span('contact.load.persons_json', function () {
                $this->loadPersons();
                return null;
            }, [
                'file' => basename($this->personsFile),
                'cache_hit' => $this->persons !== null,
                'policy' => 'local_only',
            ]);

            $trace->span('contact.load.branches_json', function () {
                $this->loadBranches();
                return null;
            }, [
                'file' => basename($this->branchesFile),
                'cache_hit' => $this->branches !== null,
                'policy' => 'local_only',
            ]);
        } else {
            // ensure loaded anyway
            $this->loadPersons();
            $this->loadBranches();
        }

        // 1) Branch code check
        $branchMatches = $trace
            ? $trace->span('contact.match.branch', function () use ($query, $qNorm) {
                return $this->matchBranchCode($query, $qNorm);
            }, [
                'query_len' => mb_strlen($query),
                'policy' => 'local_only',
            ])
            : $this->matchBranchCode($query, $qNorm);

        if ($branchMatches !== []) {
            return [
                'query' => $query,
                'query_norm' => $qNorm,
                'type' => 'branch',
                'matches' => array_slice($branchMatches, 0, $limit),
            ];
        }

        // 2) Person matching
        $personMatches = $trace
            ? $trace->span('contact.match.person', function () use ($query, $qNorm) {
                return $this->matchPersons($query, $qNorm);
            }, [
                'query_len' => mb_strlen($query),
                'policy' => 'local_only',
            ])
            : $this->matchPersons($query, $qNorm);

        if ($personMatches !== []) {
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
     * @return array<int, array{
     *   id: string,
     *   label: string,
     *   confidence: float,
     *   data: array<string, mixed>
     * }>
     */
    private function matchBranchCode(string $queryRaw, string $qNorm): array
    {
        $branches = $this->loadBranches();

        $candidate = strtoupper((string) (preg_replace('/\s+/', '', $queryRaw) ?? ''));
        $candidateNorm = $this->normalize($queryRaw);

        $results = [];

        foreach ($branches as $b) {
            $code = strtoupper((string)($b['filialenNr'] ?? ''));
            if ($code === '') {
                continue;
            }

            $isExact = ($candidate === $code);
            $isNorm  = ($candidateNorm !== '' && $candidateNorm === $this->normalize($code));

            if (!$isExact && !$isNorm) {
                continue;
            }

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

        return $results;
    }

    /**
     * Match persons by name tokens and additional fields (department/company/notes).
     *
     * Scoring guideline:
     * - 0.98: first + last name token hit
     * - 0.90: exact last name
     * - 0.75: exact first name
     * - 0.65: department/company/notes contains query
     * - 0.60: partial match in name
     *
     * @param string $queryRaw Raw query (currently only used for symmetry/debugging).
     * @param string $qNorm    Normalized query.
     *
     * @return array<int, array{
     *   id: string,
     *   label: string,
     *   confidence: float,
     *   data: array<string, mixed>
     * }>
     */
    private function matchPersons(string $queryRaw, string $qNorm): array
    {
        $persons = $this->loadPersons();

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

            if ($qNorm !== '' && $qNorm === $lastNorm && $lastNorm !== '') {
                $score = max($score, 0.90);
            }

            if ($qNorm !== '' && $qNorm === $firstNorm && $firstNorm !== '') {
                $score = max($score, 0.75);
            }

            if (count($tokens) >= 2) {
                $firstHit = ($firstNorm !== '' && in_array($firstNorm, $tokens, true));
                $lastHit  = ($lastNorm !== '' && in_array($lastNorm, $tokens, true));
                if ($firstHit && $lastHit) {
                    $score = max($score, 0.98);
                }
            }

            if ($score < 0.90 && $qNorm !== '') {
                if (
                    ($lastNorm !== '' && str_contains($lastNorm, $qNorm)) ||
                    ($firstNorm !== '' && str_contains($firstNorm, $qNorm))
                ) {
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

            if ($score <= 0.0) {
                continue;
            }

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

        usort($results, static function (array $a, array $b): int {
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
