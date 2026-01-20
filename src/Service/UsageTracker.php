<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Lightweight usage counter based on PSR-6 cache.
 *
 * Purpose:
 * - Counts how often specific application actions/services are used (usage keys).
 * - Stores counters in cache with a TTL to keep data bounded and "fresh".
 * - Maintains an index of known keys to enable listing, reporting (top), and cleanup.
 *
 * Storage model (PSR-6 cache keys):
 * - Counter values: "usage.<usage-key>" => int
 * - Index of known keys: "usage.__index" => array<int, string>
 *
 * TTL behavior:
 * - increment() sets/refreshes TTL on the counter item and on the index item.
 * - keys that are not incremented for a long time may naturally expire (depending on cache backend).
 *
 * Notes / Operational considerations:
 * - This class is intentionally simple and does not aim to be an analytics system.
 * - Concurrency: increment() performs a read-modify-write cycle; in highly concurrent scenarios,
 *   increments may be lost depending on the cache backend (typical PSR-6 limitation).
 * - The index is a best-effort list of keys; if it expires or is cleared, counters may still exist
 *   but will not be discoverable via keys()/top() until incremented again.
 */
final class UsageTracker
{
    /**
     * Cache key used to store the list of known usage keys (without "usage." prefix).
     */
    private const INDEX_KEY = 'usage.__index';

    /**
     * @param CacheItemPoolInterface $pool PSR-6 cache pool used for persisting counters and index.
     */
    public function __construct(
        private readonly CacheItemPoolInterface $pool
    ) {}

    /**
     * Increment the counter for a given usage key and return the new value.
     *
     * Side effects:
     * - Ensures the key is present in the index (usage.__index).
     * - Creates the counter if it does not exist.
     * - Refreshes TTL for both the counter item and the index item.
     *
     * Example:
     * - increment('support_chat.ask') will store/update cache item "usage.support_chat.ask".
     *
     * @param string $key        Logical usage key (without "usage." prefix).
     * @param int    $ttlSeconds Time-to-live in seconds for the counter and index entry.
     *
     * @return int The incremented counter value (>= 1).
     */
    public function increment(string $key, int $ttlSeconds = 2592000): int
    {
        $this->rememberKey($key, $ttlSeconds);

        $cacheKey = 'usage.' . $key;

        $item = $this->pool->getItem($cacheKey);
        $current = $item->isHit() ? (int) $item->get() : 0;

        $current++;
        $item->set($current);
        $item->expiresAfter($ttlSeconds);

        $this->pool->save($item);

        return $current;
    }

    /**
     * Get the current counter value for a usage key.
     *
     * @param string $key Logical usage key (without "usage." prefix).
     *
     * @return int Current count, or 0 if the counter is not present/expired.
     */
    public function get(string $key): int
    {
        $item = $this->pool->getItem('usage.' . $key);
        return $item->isHit() ? (int) $item->get() : 0;
    }

    /**
     * List known usage keys as stored in the index (without "usage." prefix).
     *
     * This list is maintained by increment() via rememberKey(). It is intended for reporting
     * and housekeeping (top(), deleteKeys()).
     *
     * @return array<int, string> List of known usage keys.
     */
    public function keys(): array
    {
        $item = $this->pool->getItem(self::INDEX_KEY);
        $val = $item->isHit() ? $item->get() : [];
        if (!is_array($val)) {
            return [];
        }

        $out = [];
        foreach ($val as $k) {
            if (is_string($k) && $k !== '') {
                $out[] = $k;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Delete multiple usage counters and clear the index.
     *
     * Behavior:
     * - Deletes each "usage.<key>" item for all provided keys.
     * - Clears the index (usage.__index).
     *
     * Notes:
     * - The method expects raw usage keys WITHOUT "usage." prefix.
     * - Returns the number of successfully deleted counter entries (not counting the index).
     *
     * @param array<int, string> $keys Usage keys to delete (without "usage." prefix).
     *
     * @return int Number of deleted counter entries.
     */
    public function deleteKeys(array $keys): int
    {
        $deleted = 0;

        foreach ($keys as $k) {
            if (!is_string($k) || $k === '') {
                continue;
            }

            if ($this->pool->deleteItem('usage.' . $k)) {
                $deleted++;
            }
        }

        // Clear index (redundant delete is harmless; kept for defensive cleanup)
        $this->pool->deleteItem(self::INDEX_KEY);
        $this->pool->deleteItem('usage.__index');

        return $deleted;
    }

    /**
     * Return the top usage keys by count.
     *
     * Implementation details:
     * - Reads all known keys from the index.
     * - Fetches each counter value and sorts descending by count.
     *
     * @param int $limit Maximum number of rows to return (minimum 1).
     *
     * @return array<int, array{key: string, count: int}>
     *     Each row contains the usage key (without prefix) and its current count.
     */
    public function top(int $limit = 20): array
    {
        $rows = [];

        foreach ($this->keys() as $key) {
            $rows[] = [
                'key' => $key,
                'count' => $this->get($key),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count']
        );

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Ensure a usage key is present in the index.
     *
     * This method is called by increment() before updating the counter value.
     * The index is stored under usage.__index and contains raw keys (no "usage." prefix).
     *
     * @param string $key        Usage key to remember (without prefix).
     * @param int    $ttlSeconds TTL to apply/refresh on the index item.
     */
    private function rememberKey(string $key, int $ttlSeconds): void
    {
        $item = $this->pool->getItem(self::INDEX_KEY);
        $keys = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $item->set($keys);
            $item->expiresAfter($ttlSeconds);
            $this->pool->save($item);
        }
    }

    /**
     * Check whether a counter exists for the given usage key.
     *
     * @param string $key Logical usage key (without "usage." prefix).
     *
     * @return bool True if the cache item "usage.<key>" exists and is not expired.
     */
    public function has(string $key): bool
    {
        return $this->pool->getItem('usage.' . $key)->isHit();
    }
}
