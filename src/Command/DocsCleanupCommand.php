<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dashtk:docs:cleanup',
    description: 'Räumt var/docs auf: verschiebt alte Root-Reports nach _legacy/ oder löscht sie (optional).'
)]
final class DocsCleanupCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Docs Root (Standard: var/docs)', 'var/docs')
            ->addOption('legacy-dir', null, InputOption::VALUE_REQUIRED, 'Zielordner für Archiv (relativ zu --dir)', '_legacy')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Zeigt nur, was passieren würde')
            ->addOption('include-unstamped', null, InputOption::VALUE_NONE, 'Nimmt auch services.md / usage_report.md / *.json im Root mit')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Löscht Dateien statt sie zu verschieben')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dir = rtrim((string) $input->getOption('dir'), '/');
        $legacyRel = trim((string) $input->getOption('legacy-dir'));
        $dryRun = (bool) $input->getOption('dry-run');
        $includeUnstamped = (bool) $input->getOption('include-unstamped');
        $delete = (bool) $input->getOption('delete');

        if ($dir === '') {
            $io->error('Option --dir ist leer.');
            return Command::FAILURE;
        }
        if (!is_dir($dir)) {
            $io->warning(sprintf('Docs-Ordner existiert nicht: %s (nichts zu tun)', $dir));
            return Command::SUCCESS;
        }

        $legacyDir = $dir . '/' . ($legacyRel !== '' ? $legacyRel : '_legacy');

        // Wir räumen nur ROOT von --dir auf (nicht in daily/weekly/monthly rein!)
        $rootFiles = $this->scanRootFiles($dir);

        // Patterns: stamped
        $moveCandidates = [];
        foreach ($rootFiles as $file) {
            $base = basename($file);

            // Niemals Unterordner anfassen
            if (!is_file($file)) {
                continue;
            }

            // Niemals _legacy selbst anfassen
            if (str_starts_with($file, $legacyDir . '/')) {
                continue;
            }

            // stamped: services_YYYY...(.md|.json) / usage_report_YYYY...(.md|.json)
            if (preg_match('~^(services|usage_report)_\d{4}-\d{2}-\d{2}_.+\.(md|json)$~', $base)) {
                $moveCandidates[] = $file;
                continue;
            }

            if ($includeUnstamped) {
                // unstamped: services.md / usage_report.md / usage_report.json / services.json etc.
                if (in_array($base, ['services.md', 'usage_report.md', 'services.json', 'usage_report.json'], true)) {
                    $moveCandidates[] = $file;
                    continue;
                }

                // optional: weitere JSONs im Root, falls ihr später mehr habt
                if (preg_match('~^(services|usage_report)\.(md|json)$~', $base)) {
                    $moveCandidates[] = $file;
                    continue;
                }
            }
        }

        $io->title('Docs Cleanup');

        $io->table(
            ['Option', 'Wert'],
            [
                ['Dir', $dir],
                ['Legacy dir', $legacyDir],
                ['Dry run', $dryRun ? 'yes' : 'no'],
                ['Include unstamped', $includeUnstamped ? 'yes' : 'no'],
                ['Mode', $delete ? 'delete' : 'move'],
            ]
        );

        if ($moveCandidates === []) {
            $io->success('Keine passenden Root-Dateien gefunden. (Nichts zu tun)');
            return Command::SUCCESS;
        }

        // Zielordner nur bei "move" notwendig
        if (!$delete && !$dryRun) {
            if (!$this->ensureDir($legacyDir)) {
                $io->error(sprintf('Konnte Legacy-Ordner nicht erstellen: %s', $legacyDir));
                return Command::FAILURE;
            }
        }

        $rows = [];
        foreach ($moveCandidates as $src) {
            $base = basename($src);
            $dst = $legacyDir . '/' . $base;

            $rows[] = [
                $delete ? 'DELETE' : 'MOVE',
                $base,
                $src,
                $delete ? '-' : $dst,
            ];
        }

        $io->section('Plan');
        $io->table(['Action', 'File', 'From', 'To'], $rows);

        if ($dryRun) {
            $io->note('DRY-RUN: Keine Änderungen durchgeführt.');
            return Command::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($moveCandidates as $src) {
            $base = basename($src);

            if ($delete) {
                if (@unlink($src)) {
                    $ok++;
                } else {
                    $fail++;
                    $io->warning(sprintf('Konnte nicht löschen: %s', $src));
                }
                continue;
            }

            $dst = $legacyDir . '/' . $base;

            // Wenn Datei bereits im Legacy existiert: nicht überschreiben -> suffix anhängen
            if (is_file($dst)) {
                $dst = $this->uniqueTarget($legacyDir, $base);
            }

            if (@rename($src, $dst)) {
                $ok++;
            } else {
                $fail++;
                $io->warning(sprintf('Konnte nicht verschieben: %s -> %s', $src, $dst));
            }
        }

        if ($fail > 0) {
            $io->warning(sprintf('Cleanup teilweise fertig: OK=%d, FAIL=%d', $ok, $fail));
            return Command::FAILURE;
        }

        $io->success(sprintf('Cleanup fertig: %d Datei(en) %s.', $ok, $delete ? 'gelöscht' : 'archiviert'));
        return Command::SUCCESS;
    }

    /**
     * @return array<int,string> absolute/relative file paths in root of $dir
     */
    private function scanRootFiles(string $dir): array
    {
        $out = [];
        $items = @scandir($dir);
        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;

            // Nur ROOT-Dateien (keine Unterordner wie daily/weekly/monthly)
            if (is_file($path)) {
                $out[] = $path;
            }
        }

        sort($out);
        return $out;
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, 0777, true) || is_dir($dir);
    }

    private function uniqueTarget(string $legacyDir, string $base): string
    {
        $ext = '';
        $name = $base;

        $pos = strrpos($base, '.');
        if ($pos !== false) {
            $name = substr($base, 0, $pos);
            $ext = substr($base, $pos); // incl dot
        }

        for ($i = 1; $i < 1000; $i++) {
            $candidate = sprintf('%s/%s__dup-%d%s', $legacyDir, $name, $i, $ext);
            if (!is_file($candidate)) {
                return $candidate;
            }
        }

        // very defensive fallback
        return sprintf('%s/%s__dup-%s%s', $legacyDir, $name, uniqid('', true), $ext);
    }
}
