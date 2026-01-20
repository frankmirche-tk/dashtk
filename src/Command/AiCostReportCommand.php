<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'dashtk:ai:cost:report',
    description: 'Erzeugt einen AI Cost Report (Tokens + Requests + EUR) aus Cache-Aggregaten.'
)]
final class AiCostReportCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('day', null, InputOption::VALUE_REQUIRED, 'Tag (YYYY-MM-DD). Default: heute', date('Y-m-d'))
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'md|json (Default: md)', 'md')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Zieldatei (optional). Wenn leer: schreibt nach STDOUT', null)
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Top N Kostentreiber (Default: 20)', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $day = (string) $input->getOption('day');
        $format = strtolower((string) $input->getOption('format'));
        $outFile = $input->getOption('output');
        $top = max(1, (int) $input->getOption('top'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $io->error('Ungültiges --day Format. Erwartet: YYYY-MM-DD');
            return Command::FAILURE;
        }
        if ($format !== 'md' && $format !== 'json') {
            $format = 'md';
        }

        // 1) Load daily index
        $indexKey = sprintf('ai_cost:index:daily:%s', $day);
        $indexItem = $this->cache->getItem($indexKey);

        if (!$indexItem->isHit()) {
            $payload = $this->renderEmpty($day, $format);
            return $this->writeOutput($output, $payload, $format, $outFile, $io);
        }

        /** @var array<int, string> $keys */
        $keys = (array) $indexItem->get();
        $keys = array_values(array_filter($keys, static fn($k) => is_string($k) && $k !== ''));

        if ($keys === []) {
            $payload = $this->renderEmpty($day, $format);
            return $this->writeOutput($output, $payload, $format, $outFile, $io);
        }

        // 2) Load aggregates
        $rows = [];
        $totals = [
            'requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cost_eur' => 0.0,
            'errors' => 0,
            'latency_ms_sum' => 0,
            'cache_hits' => 0,
        ];

        foreach ($keys as $k) {
            $item = $this->cache->getItem($k);
            if (!$item->isHit()) {
                continue;
            }

            $d = (array) $item->get();

            // Expect our stable shape from AiCostTracker.
            $rows[] = $d;

            $totals['requests'] += (int) ($d['requests'] ?? 0);
            $totals['input_tokens'] += (int) ($d['input_tokens'] ?? 0);
            $totals['output_tokens'] += (int) ($d['output_tokens'] ?? 0);
            $totals['total_tokens'] += (int) ($d['total_tokens'] ?? 0);
            $totals['cost_eur'] += (float) ($d['cost_eur'] ?? 0.0);
            $totals['errors'] += (int) ($d['errors'] ?? 0);
            $totals['latency_ms_sum'] += (int) ($d['latency_ms_sum'] ?? 0);
            $totals['cache_hits'] += (int) ($d['cache_hits'] ?? 0);
        }

        // 3) Sort by cost desc for top list
        usort($rows, static function (array $a, array $b): int {
            return ((float) ($b['cost_eur'] ?? 0.0)) <=> ((float) ($a['cost_eur'] ?? 0.0));
        });

        $rowsTop = array_slice($rows, 0, $top);

        // 4) Render
        if ($format === 'json') {
            $payload = json_encode([
                'day' => $day,
                'totals' => $totals,
                'top' => $rowsTop,
                'count' => count($rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        } else {
            $payload = $this->renderMarkdown($day, $totals, $rowsTop, count($rows));
        }

        return $this->writeOutput($output, $payload, $format, $outFile, $io);
    }

    private function renderEmpty(string $day, string $format): string
    {
        if ($format === 'json') {
            return json_encode([
                'day' => $day,
                'totals' => [
                    'requests' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'cost_eur' => 0.0,
                    'errors' => 0,
                    'latency_ms_sum' => 0,
                    'cache_hits' => 0,
                ],
                'top' => [],
                'count' => 0,
                'note' => 'No ai_cost index found for this day. Either no AI calls happened or indexing is not enabled yet.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return "# AI Cost Report – {$day}\n\n"
            . "Keine Daten gefunden (Index fehlt oder keine AI Calls an diesem Tag).\n";
    }

    private function renderMarkdown(string $day, array $totals, array $topRows, int $count): string
    {
        $avgCost = $totals['requests'] > 0 ? ($totals['cost_eur'] / $totals['requests']) : 0.0;
        $avgLatency = $totals['requests'] > 0 ? (int) round($totals['latency_ms_sum'] / $totals['requests']) : 0;

        $lines = [];
        $lines[] = "# AI Cost Report – {$day}";
        $lines[] = "";
        $lines[] = "## Totals";
        $lines[] = "";
        $lines[] = "- Aggregates: **{$count}**";
        $lines[] = "- Requests: **{$totals['requests']}**";
        $lines[] = "- Tokens (in/out/total): **{$totals['input_tokens']} / {$totals['output_tokens']} / {$totals['total_tokens']}**";
        $lines[] = "- Cost EUR: **" . number_format((float) $totals['cost_eur'], 6, '.', '') . "**";
        $lines[] = "- Avg cost / request: **" . number_format((float) $avgCost, 6, '.', '') . "**";
        $lines[] = "- Errors: **{$totals['errors']}**";
        $lines[] = "- Cache hits: **{$totals['cache_hits']}**";
        $lines[] = "- Avg latency (ms): **{$avgLatency}**";
        $lines[] = "";
        $lines[] = "## Top cost drivers (by EUR)";
        $lines[] = "";
        $lines[] = "| usage_key | provider | model | requests | tokens_in | tokens_out | tokens_total | cost_eur | errors |";
        $lines[] = "|---|---|---|---:|---:|---:|---:|---:|---:|";

        foreach ($topRows as $r) {
            $lines[] = sprintf(
                "| %s | %s | %s | %d | %d | %d | %d | %s | %d |",
                (string) ($r['usage_key'] ?? 'unknown'),
                (string) ($r['provider'] ?? 'unknown'),
                (string) ($r['model'] ?? 'unknown'),
                (int) ($r['requests'] ?? 0),
                (int) ($r['input_tokens'] ?? 0),
                (int) ($r['output_tokens'] ?? 0),
                (int) ($r['total_tokens'] ?? 0),
                number_format((float) ($r['cost_eur'] ?? 0.0), 6, '.', ''),
                (int) ($r['errors'] ?? 0),
            );
        }

        $lines[] = "";
        $lines[] = "Hinweis: Wenn Tokens = 0 bleiben, muss der AiUsageExtractor an eure Response-Objekte angepasst werden.";
        $lines[] = "";

        return implode("\n", $lines);
    }

    private function writeOutput(OutputInterface $output, string $payload, string $format, mixed $outFile, SymfonyStyle $io): int
    {
        if (is_string($outFile) && trim($outFile) !== '') {
            $dir = dirname($outFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            file_put_contents($outFile, $payload);
            $io->success(sprintf('AI Cost Report geschrieben: %s', $outFile));
            return Command::SUCCESS;
        }

        // STDOUT mode
        $output->writeln($payload);
        return Command::SUCCESS;
    }
}
