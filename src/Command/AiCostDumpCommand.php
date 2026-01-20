<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * AiCostDumpCommand
 *
 * Purpose:
 * - Debug/inspection helper for AI cost tracking.
 * - Dumps a single aggregate bucket from cache using the *exact* aggregate key format
 *   used by AiCostTracker:
 *
 *     ai_cost:daily:{day}:{usage_key}:{provider}:{model}
 *
 * When this is useful:
 * - Verify that recording works at all (HIT/MISS).
 * - Quickly inspect how a specific bucket accumulates:
 *   requests, token sums, EUR cost, errors, latency, cache hits.
 * - Debug wrong attribution (e.g. model shows as "unknown").
 *
 * Important caveats:
 * - This command reads exactly one bucket key; it does NOT use the daily index.
 *   That means:
 *   - If you pass the wrong model string, you'll see a MISS even if other buckets exist.
 *   - For "show me all buckets" use AiCostReportCommand, because it uses the daily index.
 *
 * Typical usage:
 * - php bin/console app:ai:cost:dump --day=2026-01-20 --usage-key=support_chat.ask --provider=openai --model=gpt-4o-mini
 *
 * Notes about service wiring:
 * - If you rely on cache.app in your app, you typically want to inject that explicitly:
 *   #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache
 *
 * - If you inject CacheItemPoolInterface without Autowire, Symfony may inject a different pool
 *   (depending on defaults). Your "MISS" could then simply be "wrong pool".
 */
#[AsCommand(
    name: 'app:ai:cost:dump',
    description: 'Dump AI cost aggregates for a given day / usage_key / provider / model.'
)]
final class AiCostDumpCommand extends Command
{
    /**
     * @param CacheItemPoolInterface $cache PSR-6 cache pool used to store ai_cost aggregates.
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache
    ) {
        parent::__construct();
    }

    /**
     * Configure filters for selecting a single aggregate bucket.
     *
     * Options:
     * - --day:       Day bucket (YYYY-MM-DD). Default: today.
     * - --usage-key: Business usage key. Default: support_chat.ask
     * - --provider:  Provider key. Default: gemini
     * - --model:     Exact model string. Default: unknown
     *
     * Important:
     * - The model MUST match exactly what was used as "model" in AiCostTracker::record().
     * - If your runtime model is env-driven (OPENAI_DEFAULT_MODEL / GEMINI_DEFAULT_MODEL),
     *   pass that resolved value here, not a guess.
     */
    protected function configure(): void
    {
        $this
            ->addOption('day', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD', date('Y-m-d'))
            ->addOption('usage-key', null, InputOption::VALUE_REQUIRED, 'usage_key', 'support_chat.ask')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'provider', 'gemini')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'model', 'unknown');
    }

    /**
     * Execute the dump for the selected aggregate key.
     *
     * Behavior:
     * - Prints MISS <cacheKey> if the bucket does not exist in the cache pool.
     * - Prints HIT <cacheKey> and the raw bucket payload (print_r) if present.
     *
     * @return int Always SUCCESS (because this is a debug tool).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $day = (string) $input->getOption('day');
        $usageKey = (string) $input->getOption('usage-key');
        $provider = strtolower((string) $input->getOption('provider'));
        $model = (string) $input->getOption('model');

        // Aggregate cache key used by AiCostTracker
        $cacheKey = sprintf('ai_cost:daily:%s:%s:%s:%s', $day, $usageKey, $provider, $model);

        $item = $this->cache->getItem($cacheKey);
        if (!$item->isHit()) {
            $output->writeln('<comment>MISS</comment> ' . $cacheKey);
            return Command::SUCCESS;
        }

        $output->writeln('<info>HIT</info> ' . $cacheKey);
        $output->writeln(print_r($item->get(), true));

        return Command::SUCCESS;
    }
}
