<?php

declare(strict_types=1);

namespace App\AI\Cost;

use App\AI\Usage\AiUsage;
use Psr\Cache\CacheItemPoolInterface;

/**
 * AiCostTracker
 *
 * Purpose:
 * - Aggregates AI usage and cost metrics per day into cache.
 * - Provides the persistence layer for "tokens + requests + EUR" transparency.
 * - Maintains an explicit daily index of aggregate keys so reports can be built
 *   without enumerating cache keys (important for filesystem-based cache pools).
 *
 * Core idea:
 * - Every provider call produces one "record()" event.
 * - We aggregate those events into a daily bucket keyed by:
 *
 *   day + usageKey + provider + model
 *
 * - The aggregated metrics are later read by a report command (e.g. dashtk:ai:cost:report).
 *
 * Why cache (and not DB) here?
 * - Very low friction, no schema/migrations needed.
 * - Works for cron-style reporting with short-to-mid retention.
 * - Can be swapped later (Redis / DB) without changing call sites.
 *
 * Important:
 * - This tracker is intentionally provider-agnostic.
 * - It does NOT interpret provider SDK responses; it receives extracted usage (AiUsage).
 * - Price resolution is delegated to AiCostCalculator.
 *
 * Indexing:
 * - Because many cache pools cannot list keys (filesystem cache, PSR-6),
 *   we store a daily index array:
 *
 *   ai_cost:index:daily:YYYY-MM-DD => [ 'ai_cost:daily:YYYY-MM-DD:...' , ... ]
 *
 * - Reports rely on this index to discover aggregates for the day.
 *
 * Retention:
 * - Aggregates and index entries are kept for ~90 days by default.
 * - Changing retention only affects cache expiry; it does not require code changes elsewhere.
 */
final readonly class AiCostTracker
{
    /**
     * @param CacheItemPoolInterface $cache      PSR-6 cache pool (typically cache.app).
     * @param AiCostCalculator       $calculator Pricing / EUR calculation component.
     * @param bool                   $enabled    Feature flag; when false record() is a no-op.
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private AiCostCalculator $calculator,
        private bool $enabled = true,
    ) {}

    /**
     * Record a single AI call outcome into the daily aggregates.
     *
     * What gets aggregated:
     * - requests:        total number of AI calls for this bucket
     * - input_tokens:    sum of prompt/input tokens (best-effort)
     * - output_tokens:   sum of completion/output tokens (best-effort)
     * - total_tokens:    sum of total tokens (best-effort)
     * - cost_eur:        sum of calculated EUR costs (if pricing available)
     * - errors:          count of failed calls (ok=false)
     * - latency_ms_sum:  sum of latency for avg computation in reports
     * - cache_hits:      optional count of application-level cache hits (if you later cache answers)
     *
     * Bucket key (daily aggregate):
     * - ai_cost:daily:{day}:{usageKey}:{provider}:{model}
     *
     * Daily index key:
     * - ai_cost:index:daily:{day}
     *
     * Notes on "unknown":
     * - If usageKey or model are empty/null, we normalize to "unknown".
     * - This preserves accounting integrity while signaling missing attribution.
     *
     * @param string                 $usageKey   Business usage key (e.g. "support_chat.ask").
     * @param string                 $provider   Provider key (e.g. "openai", "gemini").
     * @param string                 $model      Concrete model identifier used at runtime (env-driven).
     * @param AiUsage                $usage      Token usage extracted from the provider response.
     * @param int                    $latencyMs  Request latency in milliseconds.
     * @param bool                   $ok         Whether the call succeeded (true) or failed (false).
     * @param string|null            $errorCode  Optional normalized error code (reserved for future use).
     * @param bool                   $cacheHit   Whether the answer came from an app-side cache (optional).
     * @param \DateTimeImmutable|null $ts        Timestamp override (testing/backfills); defaults to now.
     */
    public function record(
        string $usageKey,
        string $provider,
        string $model,
        AiUsage $usage,
        int $latencyMs,
        bool $ok,
        ?string $errorCode = null,
        bool $cacheHit = false,
        ?\DateTimeImmutable $ts = null
    ): void {
        // Feature flag: allow disabling cost tracking without changing call sites.
        if (!$this->enabled) {
            return;
        }

        // Day bucketing: all metrics are aggregated per calendar day.
        $ts ??= new \DateTimeImmutable('now');
        $day = $ts->format('Y-m-d');

        // Normalize attribution keys
        $usageKey = trim($usageKey) !== '' ? trim($usageKey) : 'unknown';
        $provider = strtolower(trim($provider));
        $model = trim($model) !== '' ? trim($model) : 'unknown';

        // Ensure usage is usable even when only partial token fields are known.
        // (e.g. totalTokens exists but input/output not provided by the SDK)
        $usage = $usage->withFallbackTotal();

        // Calculate EUR costs if pricing is available (returns null if unknown).
        $cost = $this->calculator->calculateEur($provider, $model, $usage);

        // Aggregate cache key: day + usageKey + provider + model
        $cacheKey = sprintf('ai_cost:daily:%s:%s:%s:%s', $day, $usageKey, $provider, $model);

        // Maintain a daily index of aggregate keys so reports can build a list of buckets
        // without relying on cache key enumeration (filesystem cache cannot list keys).
        $indexKey = sprintf('ai_cost:index:daily:%s', $day);

        $indexItem = $this->cache->getItem($indexKey);
        $index = $indexItem->isHit() ? (array) $indexItem->get() : [];

        // Keep the index stable and deduplicated (idempotent for repeated calls).
        if (!in_array($cacheKey, $index, true)) {
            $index[] = $cacheKey;
            $indexItem->set($index);

            // Index retention: should match aggregate retention
            $indexItem->expiresAfter(60 * 60 * 24 * 90);
            $this->cache->save($indexItem);
        }

        // Load existing aggregate bucket (or initialize a fresh one).
        $item = $this->cache->getItem($cacheKey);
        $data = $item->isHit() ? (array) $item->get() : [
            'day' => $day,
            'usage_key' => $usageKey,
            'provider' => $provider,
            'model' => $model,

            // Aggregates
            'requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cost_eur' => 0.0,

            // Quality / reliability
            'errors' => 0,
            'latency_ms_sum' => 0,
            'cache_hits' => 0,
        ];

        // Aggregate request count & latency
        $data['requests'] += 1;
        $data['latency_ms_sum'] += max(0, $latencyMs);

        // Optional: counts application-level cache hits (useful later for "saved calls" KPIs).
        if ($cacheHit) {
            $data['cache_hits'] += 1;
        }

        // Error accounting: failures are tracked explicitly to compute error rate in reports.
        if (!$ok) {
            $data['errors'] += 1;
        }

        // Token aggregation (best-effort; values may be null depending on extractor support).
        if ($usage->inputTokens !== null)  { $data['input_tokens'] += $usage->inputTokens; }
        if ($usage->outputTokens !== null) { $data['output_tokens'] += $usage->outputTokens; }
        if ($usage->totalTokens !== null)  { $data['total_tokens'] += $usage->totalTokens; }

        // EUR aggregation (only if pricing could be resolved)
        if ($cost !== null) {
            $data['cost_eur'] += $cost;
        }

        $item->set($data);

        // Retention: keep aggregates for ~90 days (align with index retention).
        $item->expiresAfter(60 * 60 * 24 * 90);

        $this->cache->save($item);
    }
}
