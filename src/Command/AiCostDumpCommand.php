<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:ai:cost:dump',
    description: 'Dump AI cost aggregates for a given day / usage_key / provider / model.'
)]
final class AiCostDumpCommand extends Command
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('day', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD', date('Y-m-d'))
            ->addOption('usage-key', null, InputOption::VALUE_REQUIRED, 'usage_key', 'support_chat.ask')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'provider', 'gemini')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'model', 'unknown');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $day = (string) $input->getOption('day');
        $usageKey = (string) $input->getOption('usage-key');
        $provider = strtolower((string) $input->getOption('provider'));
        $model = (string) $input->getOption('model');

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
