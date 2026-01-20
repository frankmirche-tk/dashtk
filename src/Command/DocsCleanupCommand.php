<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * DocsCleanupCommand
 *
 * Purpose:
 * - Cleans up generated documentation report files under var/docs/ by either:
 *   - moving older snapshots into a legacy/archive directory (default), or
 *   - deleting them permanently (--delete).
 *
 * Target files:
 * - Timestamped ("stamped") snapshots created by dashtk:docs:report --stamp:
 *   - services_YYYY-MM-DD_HH-MM[-SS].{md|json}
 *   - usage_report_YYYY-MM-DD_HH-MM[-SS].{md|json}
 * - Optional: unstamped files (only if --include-unstamped is enabled):
 *   - services.md
 *   - usage_report.md
 *   - usage_report.json
 *
 * Scope handling:
 * - The command can operate on different directories ("scopes") under the base docs folder:
 *   - root     -> <dir>
 *   - daily    -> <dir>/daily
 *   - weekly   -> <dir>/weekly
 *   - monthly  -> <dir>/monthly
 *   - all      -> root + daily + weekly + monthly
 *
 * Retention (keep-last):
 * - If --keep-last > 0 is set, the command applies a per-type retention policy:
 *   - keeps the newest N snapshots for each type (services and usage_report)
 *   - everything older than N becomes eligible for cleanup (move/delete)
 * - If --keep-last = 0, no retention filter is applied (i.e. all matching files are eligible).
 *
 * Cleanup modes:
 * - Default mode ("move"):
 *   - moves files to legacy archive locations to keep history but declutter var/docs
 *   - archive paths:
 *     - scope=root    -> <legacy-dir>/
 *     - scope=daily   -> <legacy-dir>/daily/
 *     - scope=weekly  -> <legacy-dir>/weekly/
 *     - scope=monthly -> <legacy-dir>/monthly/
 * - Delete mode (--delete):
 *   - permanently deletes the eligible files
 *
 * Dry-run:
 * - With --dry-run, the command prints a plan table and performs no changes.
 * - This is recommended before enabling deletion or changing retention thresholds.
 *
 * Output:
 * - Prints an option table (what will be processed).
 * - Prints a plan table (exact files and actions).
 * - Prints a final success summary (number of processed files).
 *
 * Typical usage:
 * - Preview cleanup for daily snapshots, keeping the newest 14:
 *   - php bin/console dashtk:docs:cleanup --scope=daily --keep-last=14 --dry-run
 * - Archive old weekly snapshots (keep newest 26):
 *   - php bin/console dashtk:docs:cleanup --scope=weekly --keep-last=26
 * - Delete old root snapshots including unstamped files:
 *   - php bin/console dashtk:docs:cleanup --scope=root --include-unstamped --delete
 *
 * Notes:
 * - Missing scope folders are skipped silently (e.g. monthly not created yet).
 * - In move mode, the command tries rename() first and falls back to copy+unlink() if needed.
 * - This command is often invoked by dashtk:docs:routine as the final "maintenance" step.
 */
#[AsCommand(
    name: 'dashtk:docs:cleanup',
    description: 'Räumt Report-Dateien in var/docs auf (stamped/unstamped, delete oder move, optional mit Retention).'
)]
final class DocsCleanupCommand extends Command
{
    /**
     * Configure cleanup scope, retention and execution mode.
     *
     * - dir / legacy-dir: base folders
     * - scope: which subfolder(s) to process
     * - keep-last: per-type retention window
     * - include-unstamped: include non-stamped artifacts
     * - dry-run: plan only
     * - delete: delete instead of moving to archive
     */
    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Basis-Ordner (Standard: var/docs)', 'var/docs')
            ->addOption('legacy-dir', null, InputOption::VALUE_REQUIRED, 'Legacy-Archiv-Ordner (Standard: var/docs/_legacy)', 'var/docs/_legacy')

            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'root|daily|weekly|monthly|all', 'root')
            ->addOption('keep-last', null, InputOption::VALUE_REQUIRED, 'Behält pro Typ (services/usage_report) die letzten N Snapshots, löscht/archiviert den Rest (0=aus)', '0')

            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Zeigt nur den Plan, führt aber nichts aus')
            ->addOption('include-unstamped', null, InputOption::VALUE_NONE, 'Nimmt auch services.md / usage_report.md / usage_report.json mit')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Löscht statt zu archivieren (Mode=delete statt move)');
    }

    /**
     * Execute cleanup for the selected scope(s) and retention policy.
     *
     * Steps:
     * 1) Resolve runtime options (scope, keep-last, mode, dry-run).
     * 2) Resolve target directories for the selected scope.
     * 3) Scan each target directory for eligible report files.
     * 4) Apply retention policy (if keep-last > 0) per file type.
     * 5) Build a plan (MOVE/DELETE) and print it.
     * 6) If not dry-run, execute the plan.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on invalid scope or application errors.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $baseDir = rtrim((string) $input->getOption('dir'), '/');
        $legacyBaseDir = rtrim((string) $input->getOption('legacy-dir'), '/');

        $dryRun = (bool) $input->getOption('dry-run');
        $includeUnstamped = (bool) $input->getOption('include-unstamped');
        $mode = (bool) $input->getOption('delete') ? 'delete' : 'move';

        $scope = strtolower((string) $input->getOption('scope'));
        $keepLast = max(0, (int) $input->getOption('keep-last'));

        if (!in_array($scope, ['root', 'daily', 'weekly', 'monthly', 'all'], true)) {
            $io->error('Ungültiger scope. Erlaubt: root|daily|weekly|monthly|all');
            return Command::FAILURE;
        }

        // Determine which directories to process
        $targets = $this->resolveScopeTargets($baseDir, $scope);

        $io->title('Docs Cleanup');

        $io->table(
            ['Option', 'Wert'],
            [
                ['Dir', $baseDir],
                ['Legacy dir', $legacyBaseDir],
                ['Scope', $scope],
                ['Keep last', (string) $keepLast],
                ['Dry run', $dryRun ? 'yes' : 'no'],
                ['Include unstamped', $includeUnstamped ? 'yes' : 'no'],
                ['Mode', $mode],
            ]
        );

        $plan = [];

        foreach ($targets as $t) {
            $dirLabel = $t['label'];
            $dirPath = $t['path'];

            if (!is_dir($dirPath)) {
                // Silently skip missing scopes (e.g. monthly not generated yet).
                continue;
            }

            // Legacy dir per scope:
            // - root -> legacyBaseDir
            // - daily/weekly/monthly -> legacyBaseDir/<scope>
            $legacyDir = $dirLabel === 'root'
                ? $legacyBaseDir
                : $legacyBaseDir . '/' . $dirLabel;

            $files = $this->scanDocsDir($dirPath, $includeUnstamped);

            // Apply keep-last retention per type (services / usage_report)
            if ($keepLast > 0) {
                $files = $this->applyRetention($files, $keepLast);
            }

            foreach ($files as $f) {
                $from = $f['path'];

                if ($mode === 'delete') {
                    $plan[] = [
                        'action' => 'DELETE',
                        'file' => $f['file'],
                        'from' => $from,
                        'to' => '-',
                    ];
                    continue;
                }

                // Move mode
                $toDir = $legacyDir;
                $to = rtrim($toDir, '/') . '/' . $f['file'];

                $plan[] = [
                    'action' => 'MOVE',
                    'file' => $f['file'],
                    'from' => $from,
                    'to' => $to,
                ];
            }
        }

        if ($plan === []) {
            $io->success('Keine passenden Dateien gefunden. (Nichts zu tun)');
            return Command::SUCCESS;
        }

        // Print plan
        $io->section('Plan');
        $io->table(
            ['Action', 'File', 'From', 'To'],
            array_map(
                static fn(array $p): array => [$p['action'], $p['file'], $p['from'], $p['to']],
                $plan
            )
        );

        if ($dryRun) {
            $io->note('DRY-RUN: Keine Änderungen durchgeführt.');
            return Command::SUCCESS;
        }

        // Execute plan
        $done = 0;

        foreach ($plan as $p) {
            if ($p['action'] === 'DELETE') {
                if (@is_file($p['from'])) {
                    if (@unlink($p['from'])) {
                        $done++;
                    }
                }
                continue;
            }

            // MOVE
            $to = $p['to'];
            $toDir = dirname($to);

            if (!is_dir($toDir)) {
                @mkdir($toDir, 0777, true);
            }

            if (@is_file($p['from'])) {
                if (@rename($p['from'], $to)) {
                    $done++;
                } else {
                    // Fallback: copy+unlink
                    if (@copy($p['from'], $to) && @unlink($p['from'])) {
                        $done++;
                    }
                }
            }
        }

        $io->success(sprintf('Cleanup fertig: %d Datei(en) %s.', $done, $mode === 'delete' ? 'gelöscht' : 'archiviert'));
        return Command::SUCCESS;
    }

    /**
     * Resolve the target directories for a given scope selection.
     *
     * @param string $baseDir Base docs directory (e.g. var/docs).
     * @param string $scope   One of: root|daily|weekly|monthly|all
     *
     * @return array<int, array{label:string, path:string}> List of directories to scan.
     */
    private function resolveScopeTargets(string $baseDir, string $scope): array
    {
        if ($scope === 'root') {
            return [['label' => 'root', 'path' => $baseDir]];
        }

        if ($scope === 'all') {
            return [
                ['label' => 'root', 'path' => $baseDir],
                ['label' => 'daily', 'path' => $baseDir . '/daily'],
                ['label' => 'weekly', 'path' => $baseDir . '/weekly'],
                ['label' => 'monthly', 'path' => $baseDir . '/monthly'],
            ];
        }

        return [['label' => $scope, 'path' => $baseDir . '/' . $scope]];
    }

    /**
     * Scan a docs directory for eligible report files (stamped and optionally unstamped).
     *
     * Stamped patterns:
     * - services_YYYY-MM-DD_HH-MM[-SS].{md|json}
     * - usage_report_YYYY-MM-DD_HH-MM[-SS].{md|json}
     *
     * Unstamped artifacts (only when $includeUnstamped is true):
     * - services.md
     * - usage_report.md
     * - usage_report.json
     *
     * Timestamp extraction:
     * - For stamped files: parses the timestamp from the filename into an epoch value.
     * - If parsing fails: falls back to filemtime().
     *
     * @param string $dir             Directory path to scan.
     * @param bool   $includeUnstamped Whether to include unstamped artifacts.
     *
     * @return array<int, array{file:string, path:string, type:string, ts:int, stamped:bool}> Discovered files.
     */
    private function scanDocsDir(string $dir, bool $includeUnstamped): array
    {
        $out = [];

        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            // stamped: services_YYYY-MM-DD_HH-MM(.md|.json) or services_YYYY-MM-DD_HH-MM-SS.*
            // stamped: usage_report_YYYY-MM-DD_HH-MM(.md|.json) or usage_report_YYYY-MM-DD_HH-MM-SS.*
            $m = [];
            if (preg_match('~^(services|usage_report)_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2})(?:-(\d{2}))?\.(md|json)$~', $file, $m) === 1) {
                $type = (string) $m[1];
                $date = (string) $m[2];
                $hm = (string) $m[3];
                $ss = isset($m[4]) && $m[4] !== '' ? (string) $m[4] : '00';

                // Convert "YYYY-MM-DD" + "HH-MM-SS" to epoch
                $timeStr = $date . ' ' . str_replace('-', ':', $hm) . ':' . $ss;
                $ts = strtotime($timeStr);
                if ($ts === false) {
                    $ts = @filemtime($path) ?: 0;
                }

                $out[] = [
                    'file' => $file,
                    'path' => $path,
                    'type' => $type,
                    'ts' => (int) $ts,
                    'stamped' => true,
                ];
                continue;
            }

            if (!$includeUnstamped) {
                continue;
            }

            // Unstamped root-ish artifacts (also allowed inside scopes, if someone writes without stamp there).
            if (in_array($file, ['services.md', 'usage_report.md', 'usage_report.json'], true)) {
                $type = str_starts_with($file, 'services') ? 'services' : 'usage_report';
                $ts = @filemtime($path) ?: 0;

                $out[] = [
                    'file' => $file,
                    'path' => $path,
                    'type' => $type,
                    'ts' => (int) $ts,
                    'stamped' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * Apply a per-type retention policy and return the list of files that should be cleaned.
     *
     * Behavior:
     * - Groups files by type (services / usage_report).
     * - Sorts each group by timestamp (newest first).
     * - Keeps the newest $keepLast files of each type.
     * - Returns the remaining older files as "to-clean".
     *
     * Important:
     * - The returned array is the list of files eligible for cleanup (not the kept ones).
     *
     * @param array<int, array{file:string, path:string, type:string, ts:int, stamped:bool}> $files    Candidate files.
     * @param int                                                                           $keepLast Number of newest snapshots to keep per type.
     *
     * @return array<int, array{file:string, path:string, type:string, ts:int, stamped:bool}> Files eligible for cleanup.
     */
    private function applyRetention(array $files, int $keepLast): array
    {
        $byType = [
            'services' => [],
            'usage_report' => [],
        ];

        foreach ($files as $f) {
            $t = $f['type'] ?? '';
            if (!isset($byType[$t])) {
                // ignore unknown
                continue;
            }
            $byType[$t][] = $f;
        }

        $toClean = [];

        foreach ($byType as $type => $list) {
            // sort newest first
            usort($list, static fn(array $a, array $b): int => ((int) ($b['ts'] ?? 0) <=> (int) ($a['ts'] ?? 0)));

            // keep first N, clean the rest
            foreach (array_slice($list, $keepLast) as $f) {
                $toClean[] = $f;
            }
        }

        // stable output order: type then ts asc
        usort($toClean, static function (array $a, array $b): int {
            $ta = (string) ($a['type'] ?? '');
            $tb = (string) ($b['type'] ?? '');
            if ($ta !== $tb) {
                return strcmp($ta, $tb);
            }
            return ((int) ($a['ts'] ?? 0) <=> (int) ($b['ts'] ?? 0));
        });

        return $toClean;
    }
}
