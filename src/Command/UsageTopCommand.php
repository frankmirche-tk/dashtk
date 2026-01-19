<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UsageTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dashtk:usage:top', description: 'Zeigt die meistgenutzten Usage-Keys.')]
final class UsageTopCommand extends Command
{
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Anzahl Einträge', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $rows = $this->usage->top($limit);

        foreach ($rows as $r) {
            $output->writeln(sprintf('%6d  %s', $r['count'], $r['key']));
        }

        return Command::SUCCESS;
    }
}
