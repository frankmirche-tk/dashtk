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

/**
 * DocsRoutineCommand
 *
 * Purpose:
 * - "One command" routine wrapper intended for scheduled execution (daily/weekly/monthly).
 * - Generates a documentation snapshot package (services inventory + usage report) and optionally:
 *   - runs a strict usage lint gate before report generation
 *   - resets usage counters after report generation (typically monthly)
 *   - cleans up old documentation snapshots according to a retention policy (keep-last)
 *
 * What this command orchestrates:
 * 1) Optional gate:  dashtk:usage:lint --strict
 * 2) Core action:    dashtk:docs:report --stamp (services + usage report)
 * 3) Optional action:dashtk:usage:reset
 * 4) Optional action:dashtk:docs:cleanup (retention management for snapshots)
 *
 * Output layout:
 * - Writes a small options summary table to STDOUT (useful for cron logs).
 * - Prints each invoked command step with a numeric prefix (1..4).
 *
 * Scheduling presets:
 * - daily:
 *   - minImpact = 10
 *   - attentionWeight = 5
 *   - keepLast = 14
 *   - cleanupAfter default: ON
 *   - resetAfter default: OFF
 * - weekly:
 *   - minImpact = 5
 *   - attentionWeight = 5
 *   - keepLast = 26
 *   - cleanupAfter default: ON
 *   - resetAfter default: OFF
 * - monthly:
 *   - minImpact = 0
 *   - attentionWeight = 3
 *   - keepLast = 36
 *   - cleanupAfter default: OFF
 *   - resetAfter default: ON
 *
 * Options and precedence rules:
 * - schedule-based defaults can be overridden by explicit flags/options.
 * - reset-after:
 *   - monthly defaults to ON
 *   - --reset-after forces ON
 *   - --no-reset-after forces OFF (overrides both monthly default and --reset-after)
 * - cleanup-after:
 *   - daily+weekly defaults to ON, monthly defaults to OFF
 *   - --cleanup-after forces ON
 *   - --no-cleanup-after forces OFF (overrides defaults and --cleanup-after)
 * - keep-last:
 *   - schedule-based default unless explicitly set
 *   - 0 is allowed (means: keep nothing beyond current run; behavior depends on cleanup command)
 *
 * Safety considerations:
 * - This command does not delete usage data by default except when reset-after is enabled
 *   (monthly default).
 * - Cleanup can be configured to delete files permanently (--cleanup-delete), but defaults to move/archive.
 * - Use --cleanup-dry-run to preview cleanup effects without modifying the filesystem.
 *
 * Operational usage:
 * - Intended to be called from cron/systemd timers, e.g.:
 *   - daily:   php bin/console dashtk:docs:routine --schedule=daily --strict
 *   - weekly:  php bin/console dashtk:docs:routine --schedule=weekly --strict
 *   - monthly: php bin/console dashtk:docs:routine --schedule=monthly --strict
 *
 * Notes:
 * - This command assumes the following commands exist in the same application:
 *   - dashtk:usage:lint
 *   - dashtk:docs:report
 *   - dashtk:usage:reset
 *   - dashtk:docs:cleanup
 * - If any invoked command fails, the routine stops for critical steps (lint/report),
 *   and continues with warnings for non-critical steps (reset/cleanup).
 */
#[AsCommand(
    name: 'dashtk:docs:routine',
    description: 'Erzeugt sinnvolle Doku-/Usage-Reports für daily/weekly/monthly (Wrapper um docs:report + usage:lint + optional cleanup/reset).'
)]
final class DocsRoutineCommand extends Command
{
    /**
     * Configure CLI options for schedule-based presets, report tuning and post-actions.
     *
     * The command uses schedule-based defaults for min-impact, attention-weight and keep-last,
     * but allows overriding each value via explicit options.
     */
    protected function configure(): void
    {
        $this
            ->addOption('schedule', null, InputOption::VALUE_REQUIRED, 'daily|weekly|monthly', 'daily')

            // core params
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Root-Docs-Verzeichnis (Standard: var/docs)', 'var/docs')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'md|json (Standard: md)', 'md')

            // ai cost report
            ->addOption('ai-cost-report', null, InputOption::VALUE_NONE, 'Erzeugt zusätzlich einen AI Cost Report (Tokens + Requests + EUR)')
            ->addOption('no-ai-cost-report', null, InputOption::VALUE_NONE, 'Deaktiviert den AI Cost Report (überschreibt Default und --ai-cost-report)')


            // report tuning
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Nur Einträge mit impact >= X in Reports (Default je Schedule)', null)
            ->addOption('attention-weight', null, InputOption::VALUE_REQUIRED, 'Attention: low usage aber weight >= X (Default je Schedule)', null)

            // gates / actions
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Führt vorher usage:lint --strict aus (empfohlen)')

            // monthly default: reset-after ON (override with --no-reset-after)
            ->addOption('reset-after', null, InputOption::VALUE_NONE, 'Reset nach Report-Erzeugung (typisch monthly)')
            ->addOption('no-reset-after', null, InputOption::VALUE_NONE, 'Deaktiviert Reset (überschreibt Monthly-Default und --reset-after)')

            // cleanup (AUTO-default: daily+weekly ON, monthly OFF)
            ->addOption('cleanup-after', null, InputOption::VALUE_NONE, 'Führt nach Report-Erzeugung docs:cleanup aus')
            ->addOption('no-cleanup-after', null, InputOption::VALUE_NONE, 'Deaktiviert Cleanup (überschreibt Default und --cleanup-after)')

            // IMPORTANT: default NULL, damit wir schedule-basierte Defaults setzen können
            ->addOption('keep-last', null, InputOption::VALUE_REQUIRED, 'Wie viele Snapshots (services+usage_report) pro Scope behalten (Default je Schedule)', null)

            ->addOption('cleanup-dry-run', null, InputOption::VALUE_NONE, 'Cleanup nur planen/anzeigen, nichts verändern')
            ->addOption('cleanup-delete', null, InputOption::VALUE_NONE, 'Cleanup löscht Dateien (Default ist move/archivieren)');
    }

    /**
     * Execute the routine with the chosen schedule and options.
     *
     * Execution steps:
     * 1) Validate schedule and normalize effective options (apply presets + overrides).
     * 2) Print a summary table of effective parameters for traceability (cron logs).
     * 3) Optionally run strict usage lint gate (abort on failure).
     * 4) Run docs report generation with --stamp (abort on failure).
     * 5) Optionally reset usage counters (non-fatal on failure).
     * 6) Optionally cleanup old snapshot directories (non-fatal on failure).
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on invalid schedule or critical step failure.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schedule = strtolower((string) $input->getOption('schedule'));
        if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) {
            $output->writeln('<error>Ungültiges Schedule. Erlaubt: daily|weekly|monthly</error>');
            return Command::FAILURE;
        }

        $nsPrefix = (string) $input->getOption('namespace');

        $dirRoot = rtrim((string) $input->getOption('dir'), '/');
        $targetDir = $dirRoot . '/' . $schedule;

        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['md', 'json'], true)) {
            $format = 'md';
        }

        // Presets (feinjustierbar)
        $preset = match ($schedule) {
            'daily' => ['minImpact' => 10, 'attentionWeight' => 5, 'keepLast' => 14],
            'weekly' => ['minImpact' => 5, 'attentionWeight' => 5, 'keepLast' => 26],
            'monthly' => ['minImpact' => 0, 'attentionWeight' => 3, 'keepLast' => 36],
        };

        $minImpactOpt = $input->getOption('min-impact');
        $attentionOpt = $input->getOption('attention-weight');

        $minImpact = $minImpactOpt !== null ? (int) $minImpactOpt : (int) $preset['minImpact'];
        $attentionWeight = $attentionOpt !== null ? (int) $attentionOpt : (int) $preset['attentionWeight'];

        $strict = (bool) $input->getOption('strict');

        // reset-after: monthly default ON
        $resetAfterFlag = (bool) $input->getOption('reset-after');
        $noResetAfter = (bool) $input->getOption('no-reset-after');

        $resetAfterDefault = ($schedule === 'monthly');
        $resetAfter = $resetAfterDefault;

        if ($resetAfterFlag) {
            $resetAfter = true;
        }
        if ($noResetAfter) {
            $resetAfter = false;
        }

        // cleanup-after AUTO default
        $noCleanupAfter = (bool) $input->getOption('no-cleanup-after');
        $cleanupAfterFlag = (bool) $input->getOption('cleanup-after');

        $cleanupAfterDefault = in_array($schedule, ['daily', 'weekly'], true);
        $cleanupAfter = $cleanupAfterDefault;

        if ($cleanupAfterFlag) {
            $cleanupAfter = true;
        }
        if ($noCleanupAfter) {
            $cleanupAfter = false;
        }

        // ai-cost-report default: ON für alle schedules (daily/weekly/monthly)
        $aiCostReportFlag = (bool) $input->getOption('ai-cost-report');
        $noAiCostReport = (bool) $input->getOption('no-ai-cost-report');

        $aiCostReportDefault = true;
        $aiCostReport = $aiCostReportDefault;

        if ($aiCostReportFlag) {
            $aiCostReport = true;
        }
        if ($noAiCostReport) {
            $aiCostReport = false;
        }


        // keep-last: schedule default, aber override möglich
        $keepLastOpt = $input->getOption('keep-last');
        $keepLast = $keepLastOpt !== null ? (int) $keepLastOpt : (int) $preset['keepLast'];
        $keepLast = max(0, $keepLast);

        $cleanupDryRun = (bool) $input->getOption('cleanup-dry-run');
        $cleanupDelete = (bool) $input->getOption('cleanup-delete');

        $output->writeln('');
        $output->writeln('<info>Docs Routine</info>');
        $output->writeln(str_repeat('-', 12));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Option', 'Wert']);
        $table->setRows([
            ['Schedule', $schedule],
            ['Namespace', $nsPrefix],
            ['Dir', $targetDir],
            ['Format', $format],
            ['Min impact', (string) $minImpact],
            ['Attention weight', (string) $attentionWeight],
            ['Strict gate', $strict ? 'yes' : 'no'],
            ['Reset after', $resetAfter ? 'yes' : 'no'],
            ['Cleanup after', $cleanupAfter ? 'yes' : 'no'],
            ['Keep last', (string) $keepLast],
            ['Cleanup mode', $cleanupDelete ? 'delete' : 'move'],
            ['Cleanup dry run', $cleanupDryRun ? 'yes' : 'no'],
            ['AI cost report', $aiCostReport ? 'yes' : 'no'],
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
            $output->writeln(' <comment>1)</comment> Running: dashtk:usage:lint --strict');
            $lint = $app->find('dashtk:usage:lint');

            $lintInput = new ArrayInput([
                '--strict' => true,
                '--namespace' => $nsPrefix,
            ]);

            $code = $lint->run($lintInput, $output);
            if ($code !== Command::SUCCESS) {
                $output->writeln('<error>Abbruch: usage:lint --strict ist fehlgeschlagen.</error>');
                return Command::FAILURE;
            }
        } else {
            $output->writeln(' <comment>1)</comment> Skipping lint (no --strict).');
        }

        // 2) Doku-Paket (services + usage_report) mit Stamp
        $output->writeln(' <comment>2)</comment> Running: dashtk:docs:report --stamp (services + usage report)');

        $docs = $app->find('dashtk:docs:report');
        $docsInput = new ArrayInput([
            '--dir' => $targetDir,
            '--stamp' => true,
            '--format' => $format,

            // passthrough: namespace
            '--services-namespace' => $nsPrefix,
            '--usage-namespace' => $nsPrefix,

            // sensible default for generated docs
            '--services-only-tracked' => true,
            '--services-with-usage' => true,

            // report tuning
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
            $output->writeln(' <comment>3)</comment> Running: dashtk:usage:reset');
            $reset = $app->find('dashtk:usage:reset');
            $resetInput = new ArrayInput([]);
            $code = $reset->run($resetInput, $output);
            if ($code !== Command::SUCCESS) {
                $output->writeln('<comment>Hinweis: usage:reset ist fehlgeschlagen (Docs wurden aber erzeugt).</comment>');
            }
        } else {
            $output->writeln(' <comment>3)</comment> Skipping reset (no reset-after).');
        }

        // 4) Optional: Cleanup
        if ($cleanupAfter) {
            $output->writeln(sprintf(
                ' <comment>4)</comment> Running: dashtk:docs:cleanup --scope=%s --keep-last=%d --%s%s',
                $schedule,
                $keepLast,
                $cleanupDelete ? 'delete' : 'move',
                $cleanupDryRun ? ' --dry-run' : ''
            ));

            $cleanup = $app->find('dashtk:docs:cleanup');

            $cleanupArgs = [
                '--scope' => $schedule,
                '--keep-last' => (string) $keepLast,
            ];

            if ($cleanupDryRun) {
                $cleanupArgs['--dry-run'] = true;
            }

            if ($cleanupDelete) {
                $cleanupArgs['--delete'] = true;
            }
            // else: default ist move im Cleanup-Command

            $cleanupInput = new ArrayInput($cleanupArgs);
            $code = $cleanup->run($cleanupInput, $output);
            if ($code !== Command::SUCCESS) {
                $output->writeln('<comment>Hinweis: Cleanup ist fehlgeschlagen (Docs wurden aber erzeugt).</comment>');
            }
        } else {
            $output->writeln(' <comment>4)</comment> Skipping cleanup (default/off or --no-cleanup-after).');
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>[OK]</info> Routine fertig. schedule=%s (minImpact=%d, attentionWeight=%d)%s%s',
            $schedule,
            $minImpact,
            $attentionWeight,
            $resetAfter ? ' + reset-after' : '',
            $cleanupAfter ? ' + cleanup-after' : ''
        ));

        return Command::SUCCESS;
    }
}
