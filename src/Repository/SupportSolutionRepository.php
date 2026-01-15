<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SupportSolution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SupportSolutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportSolution::class);
    }

    /**
     * @return array<int, array{solution: SupportSolution, score: int}>
     */
    public function findBestMatches(string $input, int $limit = 3): array
    {
        $tokens = $this->tokenize($input);
        if ($tokens === []) {
            return [];
        }

        // 1) IDs + Score ermitteln (robust via DQL)
        $rows = $this->createQueryBuilder('s')
            ->select('s.id AS id, SUM(k.weight) AS score')
            ->join('s.keywords', 'k')
            ->andWhere('s.active = true')
            ->andWhere('k.keyword IN (:tokens)')
            ->setParameter('tokens', $tokens)
            ->groupBy('s.id')
            ->orderBy('score', 'DESC')
            ->addOrderBy('s.priority', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        if ($rows === []) {
            return [];
        }

        // Reihenfolge merken
        $idsInOrder = [];
        $scoresById = [];

        foreach ($rows as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $idsInOrder[] = $id;
            $scoresById[$id] = (int)($r['score'] ?? 0);
        }

        if ($idsInOrder === []) {
            return [];
        }

        // 2) Entities inkl. Steps laden
        /** @var SupportSolution[] $solutions */
        $solutions = $this->createQueryBuilder('s')
            ->leftJoin('s.steps', 'st')->addSelect('st')
            ->leftJoin('s.keywords', 'kw')->addSelect('kw')
            ->andWhere('s.id IN (:ids)')
            ->setParameter('ids', $idsInOrder)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($solutions as $s) {
            $byId[(string)$s->getId()] = $s;
        }

        // 3) Ergebnis in Score-Reihenfolge ausgeben
        $out = [];
        foreach ($idsInOrder as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $out[] = [
                'solution' => $byId[$id],
                'score' => $scoresById[$id] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Tokenizer:
     * - normalisiert
     * - entfernt Stopwörter
     * - erzeugt zusätzlich 2er- und 3er-Phrasen (Bigrams/Trigrams)
     *
     * @return string[]
     */
    private function tokenize(string $input): array
    {
        $s = mb_strtolower($input);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;

        $parts = preg_split('/\s+/u', $s) ?: [];
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

        // Synonyme / Normalisierung (vor Stopwords, damit stopwords/phrase konsistent sind)
        $map = [
            'queue' => 'warteschlange',
            'queu' => 'warteschlange',
            'spool' => 'spooler',
            'aufträge' => 'auftraege',
            'auftraege' => 'auftraege',
            'win10' => 'windows',
            'windows10' => 'windows',
            'wlan' => 'wlan',
        ];
        $parts = array_map(static fn ($p) => $map[$p] ?? $p, $parts);

        // Stopwords
        $stop = [
            'und','oder','aber','dass','ich','nicht','kein','eine','einen','der','die','das',
            'mit','auf','für','von','ist','sind','war','wie','was','wir','ihr','sie','er','es',
            'mein','meine','meinen','bitte','danke',
            // oft in Chat-Texten drin, bringt fürs Matching nix:
            'habe','hab','hast','haben','hat','hatte',
        ];

        // 1) Unigrams (Einzelwörter), Mindestlänge 3, keine Stopwords
        $unigrams = array_values(array_filter($parts, static function ($p) use ($stop) {
            if (mb_strlen($p) < 3) {
                return false;
            }
            return !in_array($p, $stop, true);
        }));

        // 2) Phrasen bilden aus den gefilterten Unigrams (damit "falscher drucker" entsteht)
        $phrases = [];

        // Bigrams
        for ($i = 0; $i < count($unigrams) - 1; $i++) {
            $phrases[] = $unigrams[$i] . ' ' . $unigrams[$i + 1];
        }

        // Trigrams (optional, aber hilfreich)
        for ($i = 0; $i < count($unigrams) - 2; $i++) {
            $phrases[] = $unigrams[$i] . ' ' . $unigrams[$i + 1] . ' ' . $unigrams[$i + 2];
        }

        // Gesamtmenge
        $tokens = array_merge($unigrams, $phrases);

        // Duplikate raus
        $tokens = array_values(array_unique($tokens));

        return $tokens;
    }
}
