<?php

declare(strict_types=1);

namespace App\AI\Cost;

use Psr\Cache\CacheItemPoolInterface;

/**
 * AiCostWindowReader
 *
 * Purpose:
 * - Reads ai_cost aggregates from cache in a way that works with filesystem adapters
 *   (no cache key enumeration required).
 * - Supports rolling windows (N days ending at a given day).
 * - Provides both:
 *   - totals per usage_key (joinable with UsageTracker/TrackUsage)
 *   - totals across all keys (executive summary KPIs)
 * - Provides previous-window deltas (same window length, immediately preceding).
 *
 * Data source:
 * - AiCostTracker writes:
 *   - aggregate key: ai_cost:daily:YYYY-MM-DD:<usageKey>:<provider>:<model>
 *   - index key:     ai_cost:index:daily:YYYY-MM-DD  (array of aggregate keys)
 *
 * Rolling windows:
 * - daily:   N=1
 * - weekly:  N=7
 * - monthly: N=30
 */
final readonly class AiCostWindowReader
{
    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {}

    /**
     * Load a rolling window (N days ending at $endDay) and return:
     * - totals overall
     * - totals grouped by usage_key
     *
     * @param string $endDay  YYYY-MM-DD
     * @param int    $days    window length in days (>=1)
     *
     * @return array{
     *   totals: array{requests:int,input_tokens:int,output_tokens:int,total_tokens:int,cost_eur:float,errors:int,cache_hits:int,latency_ms_sum:int},
     *   by_usage_key: array<string, array{requests:int,input_tokens:int,output_tokens:int,total_tokens:int,cost_eur:float,errors:int,cache_hits:int,latency_ms_sum:int}>
     * }
     */
    public function loadWindow(string $endDay, int $days): array
    {
        $days = max(1, $days);

        $totals = $this->emptyAgg();
        $byKey = [];

        foreach ($this->daysBackInclusive($endDay, $days) as $day) {
            $indexKey = sprintf('ai_cost:index:daily:%s', $day);
            $indexItem = $this->cache->getItem($indexKey);

            if (!$indexItem->isHit()) {
                continue;
            }

            $keys = (array) $indexItem->get();
            foreach ($keys as $cacheKey) {
                if (!is_string($cacheKey) || $cacheKey === '') {
                    continue;
                }

                $item = $this->cache->getItem($cacheKey);
                if (!$item->isHit()) {
                    continue;
                }

                $row = (array) $item->get();

                $usageKey = (string) ($row['usage_key'] ?? 'unknown');

                // Initialize per-key accumulator
                if (!isset($byKey[$usageKey])) {
                    $byKey[$usageKey] = $this->emptyAgg();
                }

                $this->addAgg($totals, $row);
                $this->addAgg($byKey[$usageKey], $row);
            }
        }

        return [
            'totals' => $totals,
            'by_usage_key' => $byKey,
        ];
    }

    /**
     * Load current + previous rolling window and return deltas.
     *
     * @param string $endDay YYYY-MM-DD
     * @param int    $days   window length
     *
     * @return array{
     *   current: array{totals:array, by_usage_key:array},
     *   previous: array{totals:array, by_usage_key:array},
     *   delta_totals: array{
     *     requests: array{abs:int,pct:float|null},
     *     total_tokens: array{abs:int,pct:float|null},
     *     cost_eur: array{abs:float,pct:float|null},
     *     errors: array{abs:int,pct:float|null},
     *     avg_latency_ms: array{abs:int,pct:float|null}
     *   }
     * }
     */
    public function loadWithPreviousAndDelta(string $endDay, int $days): array
    {
        $current = $this->loadWindow($endDay, $days);

        // previous window ends exactly before current window starts
        $prevEnd = $this->shiftDay($endDay, -$days);
        $previous = $this->loadWindow($prevEnd, $days);

        $curTotals = $current['totals'];
        $prevTotals = $previous['totals'];

        $curAvgLatency = $curTotals['requests'] > 0 ? (int) round($curTotals['latency_ms_sum'] / $curTotals['requests']) : 0;
        $prevAvgLatency = $prevTotals['requests'] > 0 ? (int) round($prevTotals['latency_ms_sum'] / $prevTotals['requests']) : 0;

        $deltaTotals = [
            'requests' => $this->deltaInt($curTotals['requests'], $prevTotals['requests']),
            'total_tokens' => $this->deltaInt($curTotals['total_tokens'], $prevTotals['total_tokens']),
            'cost_eur' => $this->deltaFloat($curTotals['cost_eur'], $prevTotals['cost_eur']),
            'errors' => $this->deltaInt($curTotals['errors'], $prevTotals['errors']),
            'avg_latency_ms' => $this->deltaInt($curAvgLatency, $prevAvgLatency),
        ];

        return [
            'current' => $current,
            'previous' => $previous,
            'delta_totals' => $deltaTotals,
        ];
    }

    // -----------------------------
    // Internals
    // -----------------------------

    private function emptyAgg(): array
    {
        return [
            'requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cost_eur' => 0.0,
            'errors' => 0,
            'cache_hits' => 0,
            'latency_ms_sum' => 0,
        ];
    }

    /**
     * Add one aggregate row into our accumulator.
     * Accepts both our stable row shape and partial shapes (best-effort).
     */
    private function addAgg(array &$acc, array $row): void
    {
        $acc['requests'] += (int) ($row['requests'] ?? 0);
        $acc['input_tokens'] += (int) ($row['input_tokens'] ?? 0);
        $acc['output_tokens'] += (int) ($row['output_tokens'] ?? 0);
        $acc['total_tokens'] += (int) ($row['total_tokens'] ?? 0);
        $acc['cost_eur'] += (float) ($row['cost_eur'] ?? 0.0);
        $acc['errors'] += (int) ($row['errors'] ?? 0);
        $acc['cache_hits'] += (int) ($row['cache_hits'] ?? 0);
        $acc['latency_ms_sum'] += (int) ($row['latency_ms_sum'] ?? 0);
    }

    /**
     * Return YYYY-MM-DD list for N days ending at endDay inclusive.
     */
    private function daysBackInclusive(string $endDay, int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[] = $this->shiftDay($endDay, -$i);
        }
        return $out;
    }

    private function shiftDay(string $day, int $deltaDays): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $day) ?: new \DateTimeImmutable('today');
        return $dt->modify(($deltaDays >= 0 ? '+' : '') . $deltaDays . ' days')->format('Y-m-d');
    }

    private function deltaInt(int $cur, int $prev): array
    {
        $abs = $cur - $prev;
        $pct = $prev > 0 ? (($abs / $prev) * 100.0) : null;
        return ['abs' => $abs, 'pct' => $pct];
    }

    private function deltaFloat(float $cur, float $prev): array
    {
        $abs = $cur - $prev;
        $pct = $prev > 0.0 ? (($abs / $prev) * 100.0) : null;
        return ['abs' => $abs, 'pct' => $pct];
    }
}
