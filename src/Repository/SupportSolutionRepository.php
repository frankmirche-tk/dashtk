<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SupportSolution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SupportSolutionRepository
 *
 * Purpose:
 * - Zentrale Datenzugriffsschicht für SupportSolution-Entitäten.
 * - Stellt eine domänenspezifische Suchlogik bereit, um aus Nutzer-Eingaben
 *   passende Support-Lösungen zu ermitteln.
 *
 * Charakter:
 * - Kein simples CRUD-Repository, sondern ein "Query Repository"
 * - Enthält fachliche Logik (Tokenisierung + Gewichtung), nicht nur Persistenz
 *
 * Haupt-Use-Case:
 * - Wird typischerweise vom Support-/Chat-Flow genutzt, um Knowledge-Base-
 *   Einträge anhand natürlicher Sprache zu matchen.
 */
final class SupportSolutionRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry Doctrine Registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportSolution::class);
    }

    /**
     * Findet die besten passenden Support-Lösungen zu einer freien Texteingabe.
     *
     * Ablauf (High-Level):
     * 1) Texteingabe wird tokenisiert (Normalisierung, Stopwords, Phrasen).
     * 2) Über DQL werden passende Solutions anhand ihrer Keywords gesucht.
     * 3) Treffer werden nach Score (Keyword-Gewicht) und Priorität sortiert.
     * 4) Die vollständigen Entities inkl. Steps und Keywords werden nachgeladen.
     * 5) Ergebnis wird in der berechneten Score-Reihenfolge zurückgegeben.
     *
     * Design-Entscheidungen:
     * - Zwei-Phasen-Query:
     *   - Phase 1: IDs + Score (leicht, aggregiert, sortiert)
     *   - Phase 2: Entities + Relations (gezielt nach IDs)
     *   -> verhindert unnötige JOINs bei der Score-Berechnung
     *
     * - Reihenfolge wird manuell erhalten, da Doctrine IN(:ids)
     *   keine garantierte Sortierung liefert.
     *
     * @param string $input Freitext (z. B. Nutzerfrage aus Chat)
     * @param int    $limit Maximale Anzahl an Treffern
     *
     * @return array<int, array{
     *   solution: SupportSolution,
     *   score: int
     * }>
     */
    public function findBestMatches(string $input, int $limit = 3): array
    {
        // Tokenisierung der Eingabe
        $tokens = $this->tokenize($input);
        if ($tokens === []) {
            // Keine verwertbaren Tokens -> keine Suche
            return [];
        }

        /**
         * Phase 1:
         * - Ermittelt Solution-IDs + aggregierten Score
         * - Score = SUM(keyword.weight)
         * - Nur aktive Solutions
         */
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

        /**
         * Reihenfolge und Scores merken:
         * - Doctrine liefert Entities später evtl. in anderer Reihenfolge
         * - Daher explizite Order-Liste + Score-Map
         */
        $idsInOrder = [];
        $scoresById = [];

        foreach ($rows as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $idsInOrder[] = $id;
            $scoresById[$id] = (int) ($r['score'] ?? 0);
        }

        if ($idsInOrder === []) {
            return [];
        }

        /**
         * Phase 2:
         * - Lädt vollständige Entities
         * - inkl. Steps (Anleitung) und Keywords
         */
        /** @var SupportSolution[] $solutions */
        $solutions = $this->createQueryBuilder('s')
            ->leftJoin('s.steps', 'st')->addSelect('st')
            ->leftJoin('s.keywords', 'kw')->addSelect('kw')
            ->andWhere('s.id IN (:ids)')
            ->setParameter('ids', $idsInOrder)
            ->getQuery()
            ->getResult();

        // Map: id => entity
        $byId = [];
        foreach ($solutions as $s) {
            $byId[(string) $s->getId()] = $s;
        }

        /**
         * Ergebnis wieder in Score-Reihenfolge zusammensetzen
         */
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
     * Tokenizer für Freitext-Eingaben.
     *
     * Aufgaben:
     * - Normalisiert Text (Lowercase, Sonderzeichen entfernen)
     * - Entfernt Stopwörter
     * - Wendet einfache Synonym-/Normalisierungsregeln an
     * - Erzeugt Unigrams, Bigrams und Trigrams
     *
     * Ziel:
     * - Erhöht Trefferqualität bei natürlicher Sprache
     * - Ermöglicht Matching auf Wort- und Phrasenebene
     *
     * Grenzen:
     * - Kein linguistischer Stemmer
     * - Keine Gewichtung nach Wortposition
     * - Bewusst deterministisch & DB-freundlich
     *
     * @param string $input
     * @return string[] Liste eindeutiger Tokens
     */
    private function tokenize(string $input): array
    {
        // Normalisierung: lowercase, Sonderzeichen entfernen
        $s = mb_strtolower($input);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;

        $parts = preg_split('/\s+/u', $s) ?: [];
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

        /**
         * Synonym-/Normalisierungs-Mapping
         * - reduziert Varianten (z. B. Englisch/Deutsch, Schreibweisen)
         */
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

        /**
         * Stopwörter:
         * - häufige Funktionswörter
         * - Chat-spezifische Füllwörter
         */
        $stop = [
            'und','oder','aber','dass','ich','nicht','kein','eine','einen','der','die','das',
            'mit','auf','für','von','ist','sind','war','wie','was','wir','ihr','sie','er','es',
            'mein','meine','meinen','bitte','danke',
            'habe','hab','hast','haben','hat','hatte',
        ];

        /**
         * 1) Unigrams:
         * - Mindestlänge 3
         * - keine Stopwörter
         */
        $unigrams = array_values(array_filter($parts, static function ($p) use ($stop) {
            if (mb_strlen($p) < 3) {
                return false;
            }
            return !in_array($p, $stop, true);
        }));

        /**
         * 2) Phrasen:
         * - Bigrams (2er)
         * - Trigrams (3er)
         */
        $phrases = [];

        for ($i = 0; $i < count($unigrams) - 1; $i++) {
            $phrases[] = $unigrams[$i] . ' ' . $unigrams[$i + 1];
        }

        for ($i = 0; $i < count($unigrams) - 2; $i++) {
            $phrases[] = $unigrams[$i] . ' ' . $unigrams[$i + 1] . ' ' . $unigrams[$i + 2];
        }

        // Gesamtmenge + Duplikate entfernen
        $tokens = array_merge($unigrams, $phrases);
        $tokens = array_values(array_unique($tokens));

        return $tokens;
    }
}
