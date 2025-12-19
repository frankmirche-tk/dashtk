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
     * @return string[]
     */
    private function tokenize(string $input): array
    {
        $s = mb_strtolower($input);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $parts = preg_split('/\s+/u', trim($s)) ?: [];

        $parts = array_values(array_filter($parts, fn ($p) => mb_strlen($p) >= 3));

        $stop = [
            'und','oder','aber','dass','ich','nicht','kein','eine','einen','der','die','das',
            'mit','auf','für','von','ist','sind','war','wie','was','wir','ihr','sie','er','es',
            'mein','meine','meinen','bitte','danke',
        ];
        $parts = array_values(array_filter($parts, fn ($p) => !in_array($p, $stop, true)));

        // Synonyme / Normalisierung
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
        $parts = array_map(fn ($p) => $map[$p] ?? $p, $parts);

        return array_values(array_unique($parts));
    }
}
