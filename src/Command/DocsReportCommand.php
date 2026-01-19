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
 * Builds a small "docs package" by running the existing docs commands and writing their outputs into var/docs/.
 *
 * Outputs:
 * - services.md (from dashtk:docs:services)
 * - usage_report.md (from dashtk:usage:report)
 *
 * With --stamp, outputs are timestamped:
 * - services_YYYY-MM-DD_HH-MM-SS.md
 * - usage_report_YYYY-MM-DD_HH-MM-SS.md
 */
#[AsCommand(
    name: 'dashtk:docs:report',
    description: 'Erzeugt ein Doku-Paket (services + usage_report) in var/docs/.'
)]
final class DocsReportCommand extends Command
{
    protected function configure(): void
    {
        $this
            // core output behavior
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Zielordner (Standard: var/docs)', 'var/docs')
            ->addOption('stamp', null, InputOption::VALUE_NONE, 'Schreibt timestamped Dateien, z.B. services_YYYY-MM-DD_HH-MM-SS.md')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format der Dateien: md|json (Standard: md)', 'md')

            // services.md options passthrough
            ->addOption('services-namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix für das Service-Scanning', 'App\\Service\\')
            ->addOption('services-with-usage', null, InputOption::VALUE_NONE, 'Services-Doku: zeigt TrackUsage + Usage/Impact, falls vorhanden')
            ->addOption('services-only-tracked', null, InputOption::VALUE_NONE, 'Services-Doku: zeigt nur Methoden mit #[TrackUsage(...)]')

            // usage_report.md options passthrough
            ->addOption('usage-namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix für den Usage-Report', 'App\\Service\\')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Usage-Report: Top N (Standard: 20)', '20')
            ->addOption('low', null, InputOption::VALUE_REQUIRED, 'Usage-Report: Low usage Schwelle (<= low). Standard: 2', '2')
            ->addOption('unused', null, InputOption::VALUE_REQUIRED, 'Usage-Report: Unused Schwelle (<= unused). Standard: 0', '0')
            ->addOption('critical', null, InputOption::VALUE_REQUIRED, 'Usage-Report: Critical weight threshold (>= critical). Standard: 7', '7')
            ->addOption('attention-weight', null, InputOption::VALUE_REQUIRED, 'Usage-Report: Attention weight threshold (>= attention-weight). Standard: 5', '5')
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Usage-Report: Filter impact >= min-impact (Standard: 0)', '0')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dir    = rtrim((string) $input->getOption('dir'), '/');
        $stamp  = (bool) $input->getOption('stamp');
        $format = strtolower((string) $input->getOption('format'));

        if ($format !== 'md' && $format !== 'json') {
            $format = 'md';
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // filename base
        $suffix = $stamp ? ('_' . date('Y-m-d_H-i-s')) : '';
        $ext = $format;

        $servicesOut = sprintf('%s/services%s.%s', $dir, $suffix, $ext);
        $usageOut    = sprintf('%s/usage_report%s.%s', $dir, $suffix, $ext);

        // 1) Run: dashtk:docs:services
        $servicesArgs = [
            'command' => 'dashtk:docs:services',
            'namespace' => (string) $input->getOption('services-namespace'),
            '--format' => $format,
            '--output' => $servicesOut,
        ];
        if ((bool) $input->getOption('services-with-usage')) {
            $servicesArgs['--with-usage'] = true;
        }
        if ((bool) $input->getOption('services-only-tracked')) {
            $servicesArgs['--only-tracked'] = true;
        }

        $servicesCode = $this->runCommand($output, $servicesArgs);
        if ($servicesCode !== Command::SUCCESS) {
            $io->error('Fehler beim Erzeugen von services.*');
            return $servicesCode;
        }

        // 2) Run: dashtk:usage:report
        $usageArgs = [
            'command' => 'dashtk:usage:report',
            '--namespace' => (string) $input->getOption('usage-namespace'),
            '--format' => $format,
            '--output' => $usageOut,

            '--top' => (string) $input->getOption('top'),
            '--low' => (string) $input->getOption('low'),
            '--unused' => (string) $input->getOption('unused'),
            '--critical' => (string) $input->getOption('critical'),
            '--attention-weight' => (string) $input->getOption('attention-weight'),
            '--min-impact' => (string) $input->getOption('min-impact'),
        ];

        $usageCode = $this->runCommand($output, $usageArgs);
        if ($usageCode !== Command::SUCCESS) {
            $io->error('Fehler beim Erzeugen von usage_report.*');
            return $usageCode;
        }

        $io->success("OK Doku-Paket geschrieben:\n  - {$servicesOut}\n  - {$usageOut}");
        return Command::SUCCESS;
    }

    /**
     * Runs another Symfony Console command inside the same application.
     *
     * @param array<string,mixed> $args
     */
    private function runCommand(OutputInterface $output, array $args): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>Console application not available.</error>');
            return Command::FAILURE;
        }

        $cmdName = (string) ($args['command'] ?? '');
        if ($cmdName === '') {
            $output->writeln('<error>Missing command name.</error>');
            return Command::FAILURE;
        }

        $cmd = $app->find($cmdName);

        // keep output quiet from nested runs (they write to files anyway)
        $nestedOutput = new \Symfony\Component\Console\Output\NullOutput();

        return $cmd->run(new ArrayInput($args), $nestedOutput);
    }
}
