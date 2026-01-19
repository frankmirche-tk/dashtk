<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates scheduled doc/report generation with sensible presets.
 *
 * What it does:
 * 1) Optional: runs usage:lint (strict gate)
 * 2) Runs docs:report with stamped outputs into var/docs/
 *    - services_YYYY-MM-DD_HH-MM.md (only-tracked, with-usage)
 *    - usage_report_YYYY-MM-DD_HH-MM.md (min-impact / attention-weight presets)
 * 3) Optional: usage:reset after monthly snapshot
 *
 * Examples:
 *  php bin/console dashtk:docs:routine --schedule=daily --strict
 *  php bin/console dashtk:docs:routine --schedule=weekly
 *  php bin/console dashtk:docs:routine --schedule=monthly --reset-after --strict
 */
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

            // namespace used by both scanning commands
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')

            // output config (forwarded to docs:report)
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Zielordner für Doku-Artefakte (Standard: var/docs)', 'var/docs')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format: md|json (Standard: md)', 'md')

            // report tuning (defaults depend on schedule, but can be overridden)
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Nur Einträge mit impact >= X in Reports (Default je Schedule)', null)
            ->addOption('attention-weight', null, InputOption::VALUE_REQUIRED, 'Attention: low usage aber weight >= X (Default je Schedule)', null)

            // gates / lifecycle
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Führt vorher usage:lint --strict aus (empfohlen)')
            ->addOption('reset-after', null, InputOption::VALUE_NONE, 'Optional: nach Report-Erzeugung usage reset (typisch monthly)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $schedule = strtolower((string) $input->getOption('schedule'));
        if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) {
            $io->error('Ungültiges schedule. Erlaubt: daily|weekly|monthly');
            return Command::FAILURE;
        }

        $nsPrefix = (string) $input->getOption('namespace');

        $dir = rtrim((string) $input->getOption('dir'), '/');
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['md', 'json'], true)) {
            $format = 'md';
        }

        // Presets (könnt ihr jederzeit feinjustieren)
        $preset = match ($schedule) {
            'daily'   => ['minImpact' => 10, 'attentionWeight' => 5],
            'weekly'  => ['minImpact' => 5,  'attentionWeight' => 5],
            'monthly' => ['minImpact' => 0,  'attentionWeight' => 3],
        };

        $minImpactOpt = $input->getOption('min-impact');
        $attentionOpt = $input->getOption('attention-weight');

        $minImpact = ($minImpactOpt !== null) ? (int) $minImpactOpt : (int) $preset['minImpact'];
        $attentionWeight = ($attentionOpt !== null) ? (int) $attentionOpt : (int) $preset['attentionWeight'];

        $strict = (bool) $input->getOption('strict');
        $resetAfter = (bool) $input->getOption('reset-after');

        $app = $this->getApplication();
        if ($app === null) {
            $io->error('Console Application nicht verfügbar.');
            return Command::FAILURE;
        }

        // ---- Header (damit ihr im Log sofort seht, was läuft) ----
        $io->section('Docs Routine');
        $io->definitionList(
            ['Schedule' => $schedule],
            ['Namespace' => $nsPrefix],
            ['Dir' => $dir],
            ['Format' => $format],
            ['Min impact' => (string) $minImpact],
            ['Attention weight' => (string) $attentionWeight],
            ['Strict gate' => $strict ? 'yes' : 'no'],
            ['Reset after' => $resetAfter ? 'yes' : 'no'],
        );

        // 1) Optional: Lint Gate
        if ($strict) {
            $io->text('1) Running: dashtk:usage:lint --strict');
            $lint = $app->find('dashtk:usage:lint');

            $lintInput = new ArrayInput([
                'command' => 'dashtk:usage:lint',
                '--namespace' => $nsPrefix,
                '--strict' => true,
            ]);

            $code = $lint->run($lintInput, $output);
            if ($code !== Command::SUCCESS) {
                $io->error('Abbruch: usage:lint --strict ist fehlgeschlagen.');
                return Command::FAILURE;
            }
        } else {
            $io->text('1) Skipping lint (no --strict).');
        }

        // 2) Doku-Paket (services + usage_report) mit Stamp
        $io->text('2) Running: dashtk:docs:report --stamp (services + usage report)');
        $docs = $app->find('dashtk:docs:report');

        $docsInput = new ArrayInput([
            'command' => 'dashtk:docs:report',
            '--dir' => $dir,
            '--stamp' => true,
            '--format' => $format,

            // IMPORTANT: docs:report does NOT have --namespace.
            // It has separate namespaces for services/report:
            '--services-namespace' => $nsPrefix,
            '--usage-namespace' => $nsPrefix,

            // keep docs compact + business-relevant:
            '--services-only-tracked' => true,
            '--services-with-usage' => true,

            // pass report knobs:
            '--min-impact' => (string) $minImpact,
            '--attention-weight' => (string) $attentionWeight,
        ]);

        $code = $docs->run($docsInput, $output);
        if ($code !== Command::SUCCESS) {
            $io->error('docs:report fehlgeschlagen.');
            return Command::FAILURE;
        }

        // 3) Optional: Reset
        if ($resetAfter) {
            $io->text('3) Running: dashtk:usage:reset');
            $reset = $app->find('dashtk:usage:reset');
            $resetInput = new ArrayInput([
                'command' => 'dashtk:usage:reset',
            ]);
            $rc = $reset->run($resetInput, $output);
            if ($rc !== Command::SUCCESS) {
                $io->warning('Hinweis: usage:reset ist fehlgeschlagen (Docs wurden aber erzeugt).');
                // Snapshot ist da → nicht hart failen
            }
        } else {
            $io->text('3) Skipping reset (no --reset-after).');
        }

        $io->success(sprintf(
            'Routine fertig. schedule=%s (minImpact=%d, attentionWeight=%d)%s',
            $schedule,
            $minImpact,
            $attentionWeight,
            $resetAfter ? ' + reset-after' : ''
        ));

        return Command::SUCCESS;
    }
}
