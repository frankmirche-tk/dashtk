<?php

declare(strict_types=1);

namespace App\Command;

use App\Attribute\TrackUsage;
use App\Service\UsageTracker;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'dashtk:usage:report',
    description: 'Erzeugt einen Usage-Report (Top/Low/Unused) aus TrackUsage + UsageTracker inkl. weight/impact.'
)]
final class UsageReportCommand extends Command
{
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Ausgabeformat: md|json', 'md')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Optional: Pfad in Datei schreiben (z.B. var/docs/usage_report.md)')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Top N Einträge (Standard: 20)', '20')
            ->addOption('low', null, InputOption::VALUE_REQUIRED, 'Low usage Schwelle (<= low). Standard: 2', '2')
            ->addOption('unused', null, InputOption::VALUE_REQUIRED, 'Unused Schwelle (<= unused). Standard: 0', '0')
            ->addOption('critical', null, InputOption::VALUE_REQUIRED, 'Critical threshold (weight >= critical). Standard: 7', '7')
            ->addOption('attention-weight', null, InputOption::VALUE_REQUIRED, 'Attention: low usage aber weight >= X (Default: 5)', '5')
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Filter: impact >= X (Default: 0)', '0')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nsPrefix = (string) $input->getOption('namespace');
        $format   = strtolower((string) $input->getOption('format'));
        $outFile  = $input->getOption('output') ? (string) $input->getOption('output') : null;

        $topN         = max(1, (int) $input->getOption('top'));
        $lowMax       = (int) $input->getOption('low');
        $unusedMax    = (int) $input->getOption('unused');
        $criticalMin  = (int) $input->getOption('critical');
        $attentionW   = (int) $input->getOption('attention-weight');
        $minImpact    = (int) $input->getOption('min-impact');

        $tracked = $this->collectTrackedMethods($nsPrefix);

        // Usage + Impact anreichern
        foreach ($tracked as &$r) {
            $r['seen']  = $this->usage->has($r['key']);
            $r['count'] = $this->usage->get($r['key']);
            $w = (int) ($r['weight'] ?? 1);
            if ($w < 1) {
                $w = 1;
            }
            $r['weight'] = $w;
            $r['impact'] = (int) $r['count'] * $w;
        }
        unset($r);

        $totalUsage = 0;
        $totalImpact = 0;
        foreach ($tracked as $r) {
            $totalUsage += (int)($r['count'] ?? 0);
            $totalImpact += (int)($r['impact'] ?? 0);
        }

        // Optional: min-impact Filter (für ALLE Sektionen außer Meta)
        $filtered = $tracked;
        if ($minImpact > 0) {
            $filtered = array_values(array_filter(
                $filtered,
                static fn(array $r): bool => ((int)($r['impact'] ?? 0) >= $minImpact)
            ));
        }

        // Top: primär Impact DESC, dann Count DESC, dann Key ASC
        $top = $filtered;
        usort($top, static function (array $a, array $b): int {
            $c = ((int)($b['impact'] ?? 0) <=> (int)($a['impact'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            $c = ((int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            return strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
        });
        $top = array_slice($top, 0, $topN);

        // Unused: count <= unusedMax
        $unused = array_values(array_filter(
            $filtered,
            static fn(array $r): bool => ((int)($r['count'] ?? 0) <= $unusedMax)
        ));
        usort($unused, static function (array $a, array $b): int {
            // never_seen zuerst
            $sa = ($a['seen'] ?? false) ? 1 : 0;
            $sb = ($b['seen'] ?? false) ? 1 : 0;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            // dann impact, dann count
            $c = ((int)($a['impact'] ?? 0) <=> (int)($b['impact'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            return ((int)($a['count'] ?? 0) <=> (int)($b['count'] ?? 0));
        });

        // Low: unusedMax < count <= lowMax
        $low = array_values(array_filter($filtered, static fn(array $r): bool =>
            ((int)($r['count'] ?? 0) > $unusedMax) && ((int)($r['count'] ?? 0) <= $lowMax)
        ));
        usort($low, static function (array $a, array $b): int {
            $c = ((int)($b['impact'] ?? 0) <=> (int)($a['impact'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            return ((int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0));
        });

        // Critical unused: weight >= criticalMin AND count <= unusedMax
        $criticalUnused = array_values(array_filter($filtered, static fn(array $r): bool =>
            ((int)($r['weight'] ?? 1) >= $criticalMin) && ((int)($r['count'] ?? 0) <= $unusedMax)
        ));
        usort($criticalUnused, static fn(array $a, array $b): int => ((int)($b['weight'] ?? 1) <=> (int)($a['weight'] ?? 1)));

        // Attention: low usage aber relevant (weight >= attentionW), NICHT unused
        $attention = array_values(array_filter($filtered, static fn(array $r): bool =>
            ((int)($r['count'] ?? 0) > $unusedMax) &&
            ((int)($r['count'] ?? 0) <= $lowMax) &&
            ((int)($r['weight'] ?? 1) >= $attentionW)
        ));
        usort($attention, static fn(array $a, array $b): int => ((int)($b['impact'] ?? 0) <=> (int)($a['impact'] ?? 0)));

        $summaryTop3 = array_slice($top, 0, 3);

        $meta = [
            'generatedAt'    => date('c'),
            'namespace'      => $nsPrefix,
            'topN'           => $topN,
            'lowMax'         => $lowMax,
            'unusedMax'      => $unusedMax,
            'criticalMin'    => $criticalMin,
            'attentionWeight'=> $attentionW,
            'minImpact'      => $minImpact,
            'trackedCount'   => count($tracked), // tracked total (unfiltered)
            'totalUsage'     => $totalUsage,
            'totalImpact'    => $totalImpact,
            'sortTopBy'      => 'impact_desc',
            'impactFormula'  => 'impact = usage_count * weight',
            'summary'        => [
                'top3'          => $summaryTop3,
                'attention'     => $attention,
                'criticalUnused'=> $criticalUnused,
            ],
        ];

        $payload = [
            'meta' => $meta,
            'top' => $top,
            'low' => $low,
            'unused' => $unused,
            'criticalUnused' => $criticalUnused,
        ];

        $content = match ($format) {
            'json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            default => $this->renderMarkdown($meta, $summaryTop3, $attention, $top, $low, $unused, $criticalUnused),
        };

        if ($outFile) {
            $outDir = dirname($outFile);
            if (!$this->ensureDir($outDir)) {
                $output->writeln(sprintf('<error>Kann Output-Verzeichnis nicht erstellen:</error> %s', $outDir));
                return Command::FAILURE;
            }

            file_put_contents($outFile, $content);
            $output->writeln(sprintf('<info>OK</info> geschrieben: %s', $outFile));
            return Command::SUCCESS;
        }

        $output->writeln($content);
        return Command::SUCCESS;
    }

    /**
     * @return array<int,array{key:string,weight:int,class:string,method:string,seen?:bool,count?:int,impact?:int}>
     */
    private function collectTrackedMethods(string $namespacePrefix): array
    {
        $classes = $this->discoverClasses($namespacePrefix);

        $rows = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            foreach ($ref->getMethods() as $m) {
                if ($m->getDeclaringClass()->getName() !== $ref->getName()) {
                    continue;
                }

                $attrs = $m->getAttributes(TrackUsage::class);
                if ($attrs === []) {
                    continue;
                }

                /** @var TrackUsage $inst */
                $inst = $attrs[0]->newInstance();

                $rows[] = [
                    'key' => $inst->key,
                    'weight' => $inst->weight,
                    'class' => $class,
                    'method' => $m->getName(),
                ];
            }
        }

        // unique by key
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string)$r['key']] = $r;
        }
        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * @return array<int,string>
     */
    private function discoverClasses(string $namespacePrefix): array
    {
        $baseDir = dirname(__DIR__) . '/Service';
        if (!is_dir($baseDir)) {
            return [];
        }

        $classes = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baseDir));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace($baseDir . '/', '', $file->getPathname());
            $class = $namespacePrefix . str_replace(['/', '.php'], ['\\', ''], $rel);
            $classes[] = $class;
        }

        sort($classes);
        return $classes;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<int,array<string,mixed>> $summaryTop3
     * @param array<int,array<string,mixed>> $attention
     * @param array<int,array<string,mixed>> $top
     * @param array<int,array<string,mixed>> $low
     * @param array<int,array<string,mixed>> $unused
     * @param array<int,array<string,mixed>> $criticalUnused
     */
    private function renderMarkdown(
        array $meta,
        array $summaryTop3,
        array $attention,
        array $top,
        array $low,
        array $unused,
        array $criticalUnused
    ): string {
        $md = [];
        $md[] = '# Usage Report';
        $md[] = '';
        $md[] = sprintf('- Generated: `%s`', (string) ($meta['generatedAt'] ?? ''));
        $md[] = sprintf('- Namespace: `%s`', (string) ($meta['namespace'] ?? ''));
        $md[] = sprintf('- Tracked: `%d`', (int) ($meta['trackedCount'] ?? 0));
        $md[] = sprintf('- Total Usage: `%d` | Total Impact: `%d`', (int) ($meta['totalUsage'] ?? 0), (int) ($meta['totalImpact'] ?? 0));
        $md[] = sprintf(
            '- Top: `%d` | Low <= `%d` | Unused <= `%d` | Critical weight >= `%d`',
            (int) ($meta['topN'] ?? 20),
            (int) ($meta['lowMax'] ?? 2),
            (int) ($meta['unusedMax'] ?? 0),
            (int) ($meta['criticalMin'] ?? 7)
        );
        $md[] = sprintf('- Attention weight >= `%d`', (int) ($meta['attentionWeight'] ?? 5));
        $md[] = sprintf('- Min Impact filter: `impact >= %d`', (int) ($meta['minImpact'] ?? 0));
        $md[] = sprintf('- Sort Top: `%s` | Formula: `%s`', (string) ($meta['sortTopBy'] ?? ''), (string) ($meta['impactFormula'] ?? ''));
        $md[] = '';

        $md[] = '## Executive Summary';
        $md[] = '';
        $md[] = sprintf('- Tracked Entry Points: `%d`', (int) ($meta['trackedCount'] ?? 0));
        $md[] = sprintf('- Total Usage: `%d`', (int) ($meta['totalUsage'] ?? 0));
        $md[] = sprintf('- Total Impact: `%d`', (int) ($meta['totalImpact'] ?? 0));
        $md[] = '';

        $md[] = '### Top 3 (by impact)';
        $md[] = '';
        if ($summaryTop3 === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | Key | Location |';
            $md[] = '|------:|------:|------:|-----|----------|';
            foreach ($summaryTop3 as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        $md[] = '### Attention (low usage but relevant)';
        $md[] = '';
        if ($attention === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | Key | Location |';
            $md[] = '|------:|------:|------:|-----|----------|';
            foreach ($attention as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        $md[] = '### Critical unused';
        $md[] = '';
        if ($criticalUnused === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Weight | Key | Location |';
            $md[] = '|------:|-----|----------|';
            foreach ($criticalUnused as $r) {
                $md[] = sprintf(
                    '| %d | `%s` | `%s::%s()` |',
                    (int) ($r['weight'] ?? 1),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        $md[] = '## Top (by impact)';
        $md[] = '';
        if ($top === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | Key | Location |';
            $md[] = '|------:|------:|------:|-----|----------|';
            foreach ($top as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        $md[] = '## Low usage';
        $md[] = '';
        if ($low === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | Key | Location |';
            $md[] = '|------:|------:|------:|-----|----------|';
            foreach ($low as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        $md[] = '## Unused';
        $md[] = '';
        if ($unused === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Status | Impact | Count | Weight | Key | Location |';
            $md[] = '|--------|------:|------:|------:|-----|----------|';
            foreach ($unused as $r) {
                $status = ($r['seen'] ?? false) ? 'seen' : 'never_seen';
                $md[] = sprintf(
                    '| %s | %d | %d | %d | `%s` | `%s::%s()` |',
                    $status,
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        $md[] = '## Critical unused';
        $md[] = '';
        if ($criticalUnused === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Weight | Key | Location |';
            $md[] = '|------:|-----|----------|';
            foreach ($criticalUnused as $r) {
                $md[] = sprintf(
                    '| %d | `%s` | `%s::%s()` |',
                    (int) ($r['weight'] ?? 1),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        return implode("\n", $md);
    }

    private function ensureDir(string $dir): bool
    {
        if ($dir === '' || $dir === '.' || $dir === '/') {
            return true;
        }

        if (is_dir($dir)) {
            return true;
        }

        if (@mkdir($dir, 0777, true)) {
            return true;
        }

        return is_dir($dir);
    }
}
