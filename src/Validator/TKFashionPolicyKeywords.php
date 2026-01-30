<?php

namespace App\Validator;

final class TKFashionPolicyKeywords
{
    /** @var array<string,true> */
    private array $blacklist = [];

    public function __construct(
        private readonly string $configPath
    ) {
        $this->blacklist = $this->loadBlacklist($configPath);
    }

    public function normalize(string $kw): string
    {
        $kw = mb_strtolower(trim($kw));
        $kw = preg_replace('/\s+/u', ' ', $kw) ?? $kw;
        return $kw;
    }

    public function isAllowed(string $kw): bool
    {
        $kw = $this->normalize($kw);
        if ($kw === '') return false;

        // Blacklist aus Config
        if (isset($this->blacklist[$kw])) return false;

        // Mindestlänge
        if (mb_strlen($kw) < 3) return false;

        // ❌ reine Zahlen
        if (preg_match('/^\d+$/', $kw)) return false;

        // ❌ Codes / Kürzel (z. B. AB12)
        if (preg_match('/^[a-z0-9]{4,5}$/i', $kw)) return false;

        // ❌ Kalenderwochen
        if (preg_match('/^kw[\s\-_]?\d{1,2}$/i', $kw)) return false;

        return true;
    }


    /**
     * @param array<int,array{keyword?:mixed, weight?:mixed}> $keywords
     * @return array<int,array{keyword:string, weight:int}>
     */
    public function filterKeywordObjects(array $keywords, int $max = 20): array
    {
        $out = [];
        foreach ($keywords as $k) {
            if (!is_array($k)) continue;

            $raw = (string)($k['keyword'] ?? '');
            $kw = $this->normalize($raw);

            if (!$this->isAllowed($kw)) continue;

            $weight = (int)($k['weight'] ?? 6);
            $weight = max(1, min(10, $weight));

            // dedupe by keyword
            $out[$kw] = ['keyword' => $kw, 'weight' => $weight];

            if (count($out) >= $max) break;
        }
        return array_values($out);
    }

    /** @return array<string,true> */
    private function loadBlacklist(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $set = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $set[mb_strtolower($line)] = true;
        }
        return $set;
    }
}
