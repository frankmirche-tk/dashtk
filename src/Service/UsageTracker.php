<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

final class UsageTracker
{
    private const INDEX_KEY = 'usage.__index';

    public function __construct(
        private readonly CacheItemPoolInterface $pool
    ) {}

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

    public function get(string $key): int
    {
        $item = $this->pool->getItem('usage.' . $key);
        return $item->isHit() ? (int) $item->get() : 0;
    }

    /**
     * @return array<int,string> Liste bekannter Usage-Keys (ohne "usage." Prefix)
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
     * Löscht usage.__index und alle usage.<key> Einträge.
     *
     * @param array<int,string> $keys
     * @return int Anzahl gelöschter Keys (ohne Index)
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

        $this->pool->deleteItem(self::INDEX_KEY);


        // Index zuletzt löschen (damit wir die Liste vorher noch haben)
        $this->pool->deleteItem('usage.__index');

        return $deleted;
    }

    /**
     * @return array<int,array{key:string,count:int}>
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

    public function has(string $key): bool
    {
        return $this->pool->getItem('usage.' . $key)->isHit();
    }

}
