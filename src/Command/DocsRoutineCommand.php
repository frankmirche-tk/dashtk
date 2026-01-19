<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'dashtk:docs:routine',
    description: 'Erzeugt sinnvolle Doku-/Usage-Reports für daily/weekly/monthly (Wrapper um docs:report + usage:lint).'
)]
final class DocsRoutineCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('schedule', null, InputOption::VALUE_REQUIRED, 'daily|weekly|monthly', 'daily')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')

            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Basis-Zielordner (Standard: var/docs). Pro Schedule wird ein Unterordner genutzt.', 'var/docs')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'md|json (Standard: md)', 'md')

            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Nur Einträge mit impact >= X in Reports (Default je Schedule)', null)
            ->addOption('attention-weight', null, InputOption::VALUE_REQUIRED, 'Attention: low usage aber weight >= X (Default je Schedule)', null)

            ->addOption('strict', null, InputOption::VALUE_NONE, 'Führt vorher usage:lint --strict aus (empfohlen)')
            ->addOption('reset-after', null, InputOption::VALUE_NONE, 'Optional: nach Report-Erzeugung usage reset (eher monthly)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schedule = strtolower((string) $input->getOption('schedule'));
        if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) {
            $output->writeln('<error>Ungültiges Profil. Erlaubt: daily|weekly|monthly</error>');
            return Command::FAILURE;
        }

        $nsPrefix = (string) $input->getOption('namespace');

        $baseDir = rtrim((string) $input->getOption('dir'), '/');
        $dir = $baseDir . '/' . $schedule;

        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['md', 'json'], true)) {
            $format = 'md';
        }

        // Presets (feinjustierbar)
        $preset = match ($schedule) {
            'daily' => ['minImpact' => 10, 'attentionWeight' => 5],
            'weekly' => ['minImpact' => 5, 'attentionWeight' => 5],
            'monthly' => ['minImpact' => 0, 'attentionWeight' => 3],
        };

        $minImpactOpt = $input->getOption('min-impact');
        $attentionOpt = $input->getOption('attention-weight');

        $minImpact = $minImpactOpt !== null ? (int) $minImpactOpt : (int) $preset['minImpact'];
        $attentionWeight = $attentionOpt !== null ? (int) $attentionOpt : (int) $preset['attentionWeight'];

        $strict = (bool) $input->getOption('strict');
        $resetAfter = (bool) $input->getOption('reset-after');

        $output->writeln('');
        $output->writeln('<info>Docs Routine</info>');
        $output->writeln(str_repeat('-', 12));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['', '']);
        $table->setRows([
            ['Schedule', $schedule],
            ['Namespace', $nsPrefix],
            ['Dir', $dir],
            ['Format', $format],
            ['Min impact', (string) $minImpact],
            ['Attention weight', (string) $attentionWeight],
            ['Strict gate', $strict ? 'yes' : 'no'],
            ['Reset after', $resetAfter ? 'yes' : 'no'],
        ]);
        $table->render();
        $output->writeln('');

        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>Console Application nicht verfügbar.</error>');
            return Command::FAILURE;
        }

        // 1) Optional: Lint Gate
        if ($strict) {
            $output->writeln(' <comment>1) Running: dashtk:usage:lint --strict</comment>');

            $lint = $app->find('dashtk:usage:lint');
            $lintInput = new ArrayInput([
                'command' => 'dashtk:usage:lint',
                '--namespace' => $nsPrefix,
                '--strict' => true,
            ]);

            $code = $lint->run($lintInput, $output);
            if ($code !== Command::SUCCESS) {
                $output->writeln('<error>Abbruch: usage:lint --strict ist fehlgeschlagen.</error>');
                return Command::FAILURE;
            }
        } else {
            $output->writeln(' <comment>1) Skipping lint (no --strict).</comment>');
        }

        // 2) docs:report (stamped) -> IMPORTANT: pass --dir=$dir so it writes into schedule folder
        $output->writeln(' <comment>2) Running: dashtk:docs:report --stamp (services + usage report)</comment>');

        $docs = $app->find('dashtk:docs:report');
        $docsInput = new ArrayInput([
            'command' => 'dashtk:docs:report',
            '--stamp' => true,
            '--dir' => $dir,
            '--format' => $format,

            // passthrough for services generation:
            '--services-namespace' => $nsPrefix,
            '--services-only-tracked' => true,
            '--services-with-usage' => true,

            // passthrough for usage report:
            '--usage-namespace' => $nsPrefix,
            '--min-impact' => (string) $minImpact,
            '--attention-weight' => (string) $attentionWeight,
        ]);

        $code = $docs->run($docsInput, $output);
        if ($code !== Command::SUCCESS) {
            $output->writeln('<error>docs:report fehlgeschlagen.</error>');
            return Command::FAILURE;
        }

        // 3) Optional: Reset
        if ($resetAfter) {
            $output->writeln(' <comment>3) Running: dashtk:usage:reset</comment>');

            $reset = $app->find('dashtk:usage:reset');
            $resetInput = new ArrayInput([
                'command' => 'dashtk:usage:reset',
            ]);

            $code = $reset->run($resetInput, $output);
            if ($code !== Command::SUCCESS) {
                $output->writeln('<comment>Hinweis: usage:reset ist fehlgeschlagen (Docs wurden aber erzeugt).</comment>');
            }
        } else {
            $output->writeln(' <comment>3) Skipping reset (no --reset-after).</comment>');
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>[OK]</info> Routine fertig. schedule=%s (minImpact=%d, attentionWeight=%d)',
            $schedule,
            $minImpact,
            $attentionWeight
        ));
        $output->writeln('');

        return Command::SUCCESS;
    }
}
