<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UsageTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'dashtk:usage:reset',
    description: 'Löscht Usage-Counter (usage.*) aus dem Cache, ohne den restlichen Cache zu leeren.'
)]
final class UsageResetCommand extends Command
{
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Optional: nur Keys mit Prefix löschen (z.B. support_chat.)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, was gelöscht würde (nichts wird gelöscht)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prefix = $input->getOption('prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : null;

        $dryRun = (bool) $input->getOption('dry-run');

        $keys = $this->usage->keys(); // aus usage.__index
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));

        if ($prefix !== null) {
            $keys = array_values(array_filter($keys, static fn(string $k) => str_starts_with($k, $prefix)));
        }

        if ($keys === []) {
            $output->writeln('<info>OK</info> Keine Usage-Keys gefunden (nichts zu tun).');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '%s %d Usage-Key(s)%s:',
            $dryRun ? '<comment>DRY-RUN:</comment> würde löschen' : '<info>RESET:</info> lösche',
            count($keys),
            $prefix !== null ? sprintf(' (prefix: %s)', $prefix) : ''
        ));

        foreach ($keys as $k) {
            $output->writeln('  - usage.' . $k);
        }

        if ($dryRun) {
            $output->writeln('<comment>DRY-RUN</comment> Keine Änderungen vorgenommen.');
            return Command::SUCCESS;
        }

        $deleted = $this->usage->deleteKeys($keys);

        $output->writeln(sprintf('<info>OK</info> %d Key(s) gelöscht.', $deleted));
        return Command::SUCCESS;
    }
}
