<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UsageTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dashtk:usage:show', description: 'Zeigt Usage Counter an.')]
final class UsageShowCommand extends Command
{
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Usage-Key, z.B. support_chat.ask');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument('key');

        if ($key === '__index') {
            $keys = $this->usage->keys();

            $output->writeln('usage.__index:');
            if ($keys === []) {
                $output->writeln(' (empty)');
                return Command::SUCCESS;
            }

            foreach ($keys as $k) {
                $output->writeln(' - ' . $k);
            }

            return Command::SUCCESS;
        }

        $val = $this->usage->get($key);
        $output->writeln(sprintf('usage.%s = %d', $key, $val));

        return Command::SUCCESS;
    }

}
