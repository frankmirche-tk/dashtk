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

/**
 * AiCostReportCommand
 *
 * Purpose:
 * - Generates an "AI Cost Report" for a given day based on cached aggregates produced by AiCostTracker.
 * - The report provides immediate transparency across:
 *   - request counts
 *   - token usage (input/output/total)
 *   - calculated EUR costs
 *   - error counts and average latency
 *
 * Why a report command (and not just logs)?
 * - Logs are great for debugging but hard to summarize into stable daily KPIs.
 * - This command produces deterministic "daily snapshots" and can be:
 *   - executed manually during development
 *   - executed via cron / systemd timers
 *   - called by DocsRoutineCommand to generate docs packages (daily/weekly/monthly)
 *
 * Data source:
 * - AiCostTracker writes daily aggregates into cache with keys:
 *     ai_cost:daily:{day}:{usageKey}:{provider}:{model}
 *
 * - Because many cache implementations cannot enumerate keys (filesystem cache),
 *   AiCostTracker also maintains a daily index:
 *     ai_cost:index:daily:{day} => array of aggregate keys
 *
 * This command relies on that index to discover all aggregate buckets for the day.
 *
 * Output formats:
 * - md   : human readable markdown report (good for docs snapshots and git diffs)
 * - json : machine readable report (good for dashboards / pipelines)
 *
 * Operational notes:
 * - "Empty report" can mean:
 *   - no AI calls happened on that day
 *   - AiCostTracker was disabled (ai_cost.enabled=false)
 *   - the index key expired / was cleared (cache:clear in dev)
 *
 * Limitations:
 * - The report does not "repair" missing data. If tokens are 0, the extractor must be adapted.
 * - The report assumes the aggregate payload shape produced by AiCostTracker is stable.
 */
#[AsCommand(
    name: 'dashtk:ai:cost:report',
    description: 'Erzeugt einen AI Cost Report (Tokens + Requests + EUR) aus Cache-Aggregaten.'
)]
final class AiCostReportCommand extends Command
{
    /**
     * @param CacheItemPoolInterface $cache cache.app PSR-6 pool that stores ai_cost aggregates and index keys.
     */
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    /**
     * Configure CLI options.
     *
     * Options:
     * - --day:    Date bucket to report (YYYY-MM-DD). Defaults to today.
     * - --format: Output format (md|json). Defaults to md.
     * - --output: Optional file path. If not provided, prints to STDOUT.
     * - --top:    Number of top cost drivers included in the report (sorted by EUR desc).
     */
    protected function configure(): void
    {
        $this
            ->addOption('day', null, InputOption::VALUE_REQUIRED, 'Tag (YYYY-MM-DD). Default: heute', date('Y-m-d'))
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'md|json (Default: md)', 'md')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Zieldatei (optional). Wenn leer: schreibt nach STDOUT', null)
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Top N Kostentreiber (Default: 20)', '20');
    }

    /**
     * Execute the report generation.
     *
     * High-level flow:
     * 1) Validate input options (day format, output format).
     * 2) Load daily index key ai_cost:index:daily:{day}.
     * 3) Load each aggregate key listed in the index.
     * 4) Compute totals (requests, tokens, EUR, errors, latency, cache hits).
     * 5) Sort buckets by cost and render top list.
     * 6) Write to file or STDOUT.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on invalid options.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $day = (string) $input->getOption('day');
        $format = strtolower((string) $input->getOption('format'));
        $outFile = $input->getOption('output');
        $top = max(1, (int) $input->getOption('top'));

        // Defensive validation: date bucket must be stable and deterministic.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $io->error('Ungültiges --day Format. Erwartet: YYYY-MM-DD');
            return Command::FAILURE;
        }

        // Output format: default to markdown for human readability.
        if ($format !== 'md' && $format !== 'json') {
            $format = 'md';
        }

        // 1) Load daily index (required for cache pools without key enumeration).
        $indexKey = sprintf('ai_cost:index:daily:%s', $day);
        $indexItem = $this->cache->getItem($indexKey);

        // Index miss = either no calls or indexing not yet created (or cache was cleared).
        if (!$indexItem->isHit()) {
            $payload = $this->renderEmpty($day, $format);
            return $this->writeOutput($output, $payload, $format, $outFile, $io);
        }

        /** @var array<int, string> $keys */
        $keys = (array) $indexItem->get();

        // Remove invalid entries and keep stable numeric ordering.
        $keys = array_values(array_filter($keys, static fn($k) => is_string($k) && $k !== ''));

        // Empty index means: index exists, but contains no aggregates (edge case).
        if ($keys === []) {
            $payload = $this->renderEmpty($day, $format);
            return $this->writeOutput($output, $payload, $format, $outFile, $io);
        }

        // 2) Load aggregates and compute totals
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
                // Aggregate key listed in index but expired/missing → skip.
                // (We do not mutate index here; AiCostTracker maintains it.)
                continue;
            }

            $d = (array) $item->get();

            // Expect the stable shape produced by AiCostTracker (day, usage_key, provider, model, aggregates).
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

        // 3) Sort by cost descending to identify top cost drivers.
        usort($rows, static function (array $a, array $b): int {
            return ((float) ($b['cost_eur'] ?? 0.0)) <=> ((float) ($a['cost_eur'] ?? 0.0));
        });

        $rowsTop = array_slice($rows, 0, $top);

        // 4) Render payload
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

    /**
     * Render an "empty report" payload.
     *
     * When does this happen?
     * - No index exists for the day (no calls, tracking disabled, or cache cleared).
     * - Index exists but contains no keys (edge case).
     *
     * @param string $day    Requested day bucket (YYYY-MM-DD).
     * @param string $format md|json
     *
     * @return string Rendered report.
     */
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

    /**
     * Render report as Markdown.
     *
     * Includes:
     * - Totals section with derived KPIs (avg cost, avg latency)
     * - Top cost drivers table
     * - Operational hint if tokens appear to be missing
     *
     * @param string $day      Requested day bucket.
     * @param array  $totals   Totals aggregate array computed from daily buckets.
     * @param array  $topRows  Subset of rows sorted by cost desc.
     * @param int    $count    Number of aggregate buckets found for the day.
     *
     * @return string Markdown report.
     */
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

    /**
     * Write the rendered report either to a file or to STDOUT.
     *
     * File mode:
     * - Creates the directory path best-effort.
     * - Overwrites existing file content.
     *
     * STDOUT mode:
     * - Prints the payload (useful for piping into other commands).
     *
     * @param OutputInterface $output  Console output.
     * @param string          $payload Report content (markdown or json).
     * @param string          $format  md|json (kept for potential future branching).
     * @param mixed           $outFile Output file option value.
     * @param SymfonyStyle    $io      SymfonyStyle for user-friendly CLI messages.
     *
     * @return int Command::SUCCESS always (unless option validation failed earlier).
     */
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
