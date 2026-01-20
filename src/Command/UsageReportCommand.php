<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Cost\AiCostWindowReader;
use App\Attribute\TrackUsage;
use App\Service\UsageTracker;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * UsageReportCommand
 *
 * Purpose:
 * - Generates an "actionability focused" usage report from:
 *   - declared tracking entry points via #[TrackUsage(key, weight)]
 *   - real counters stored in UsageTracker (cache-backed)
 * - Adds business/ops prioritization via:
 *   - weight (importance/criticality)
 *   - impact = count * weight (prioritization score)
 *
 * Why this report exists:
 * - A raw "top usage" list is helpful but often misses operational reality:
 *   - low usage can be fine, unless the entry point is critical (high weight)
 *   - high usage might be noise if weight is low
 * - impact lets you prioritize changes, testing, and documentation work.
 *
 * Definitions used:
 * - count:
 *   - UsageTracker counter value for the key (usage.<key>)
 * - seen:
 *   - UsageTracker::has($key) indicates whether the key currently exists in cache
 * - weight:
 *   - criticality of the entry point (expected range 1..10)
 * - impact:
 *   - computed as: impact = count * weight
 *
 * Sections produced:
 * 1) Meta:
 *    - generation time, namespace, thresholds, totals, formula, tracking count
 * 2) Executive Summary:
 *    - totals + highlights (Top 3, Attention, Critical unused)
 *    - AI cost KPIs (rolling 1/7/30) + delta vs previous window (Variant 1)
 *    - Top cost drivers (Top 3 by EUR)
 * 3) Top (by impact):
 *    - highest impact entry points (impact DESC, count DESC)
 *    - includes AI columns (requests/EUR/EUR per req/errors/latency) for better prioritization
 * 4) Low usage:
 *    - unusedMax < count <= lowMax
 * 5) Unused:
 *    - count <= unusedMax (includes "never_seen" emphasis)
 * 6) Critical unused:
 *    - weight >= critical AND count <= unusedMax
 * 7) Attention:
 *    - low usage but relevant (weight >= attention-weight), not unused
 *
 * Options:
 * - --namespace:
 *   - namespace prefix used to map src/Service files to FQCNs (default App\\Service\\)
 * - --format:
 *   - md (default) or json
 * - --output / -o:
 *   - optional file path for writing the report
 * - --top:
 *   - number of entries in Top list (default 20)
 * - --low:
 *   - low usage threshold (<= low) (default 2)
 * - --unused:
 *   - unused threshold (<= unused) (default 0)
 * - --critical:
 *   - "critical" weight threshold (>= critical) (default 7)
 * - --attention-weight:
 *   - weight threshold for "Attention" list (default 5)
 * - --min-impact:
 *   - global filter (only include items with impact >= min-impact in all report sections)
 *
 * AI cost (Variant 1, rolling windows):
 * - --period:
 *   - daily|weekly|monthly (default daily)
 * - --day:
 *   - end day for the window YYYY-MM-DD (default today)
 * - Window sizes:
 *   - daily:   1 day
 *   - weekly:  7 days
 *   - monthly: 30 days
 * - Delta:
 *   - computed against the immediately preceding window of the same length
 *
 * Requirements:
 * - This command expects an AiCostWindowReader to be available as $this->aiCostReader.
 *
 * Notes / limitations:
 * - The tracked methods list is derived from source scanning under src/Service and reflection.
 * - Counters are cache-based and TTL-dependent; "never_seen" may also occur when counters expired.
 * - collectTrackedMethods() deduplicates by key; if two methods accidentally share a key, only one wins.
 */
#[AsCommand(
    name: 'dashtk:usage:report',
    description: 'Erzeugt einen Usage-Report (Top/Low/Unused) aus TrackUsage + UsageTracker inkl. weight/impact + AI Cost (rolling 1/7/30).'
)]
final class UsageReportCommand extends Command
{
    public function __construct(
        private readonly UsageTracker $usage,
        private readonly AiCostWindowReader $aiCostReader,
    ) {
        parent::__construct();
    }

    /**
     * Configure CLI options for report generation.
     *
     * Options allow:
     * - tuning thresholds (top/low/unused/critical/attention-weight/min-impact)
     * - selecting output format (md/json)
     * - writing output directly into a file (docs pipeline)
     * - configuring the AI cost rolling window (period/day)
     */
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

            // Variant 1 (rolling windows)
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'AI cost rolling window: daily|weekly|monthly (Default: daily)', 'daily')
            ->addOption('day', null, InputOption::VALUE_REQUIRED, 'AI cost end day: YYYY-MM-DD (Default: heute)', date('Y-m-d'));
    }

    /**
     * Executes report generation.
     *
     * Steps:
     * 1) Collect tracked methods (keys + weight + location).
     * 2) Enrich each tracked method with usage counters (count/seen) and compute impact.
     * 3) Load AI cost aggregates (rolling window) and join them by usage_key.
     * 4) Compute totals (totalUsage / totalImpact) + AI totals for executive summary.
     * 5) Apply optional min-impact filtering for all sections.
     * 6) Build report sections (top/low/unused/criticalUnused/attention + summaryTop3).
     * 7) Render as Markdown or JSON (including executive summary + AI deltas + cost drivers).
     * 8) Print to console or write to output file.
     *
     * Notes on AI cost integration (Variant 1, rolling windows):
     * - We join AI cost by the same business key used in TrackUsage/UsageTracker: usage_key.
     * - Rolling window defaults:
     *   - daily:   1 day  (the given --day)
     *   - weekly:  7 days (end at --day)
     *   - monthly: 30 days (end at --day)
     * - Delta is computed against the immediately preceding window of the same length.
     *
     * Requirements:
     * - This command expects an AiCostWindowReader to be available as $this->aiCostReader.
     * - The reader loads aggregates written by AiCostTracker via the daily index key:
     *   ai_cost:index:daily:YYYY-MM-DD
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on output directory errors.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nsPrefix = (string) $input->getOption('namespace');
        $format   = strtolower((string) $input->getOption('format'));
        $outFile  = $input->getOption('output') ? (string) $input->getOption('output') : null;

        $topN        = max(1, (int) $input->getOption('top'));
        $lowMax      = (int) $input->getOption('low');
        $unusedMax   = (int) $input->getOption('unused');
        $criticalMin = (int) $input->getOption('critical');
        $attentionW  = (int) $input->getOption('attention-weight');
        $minImpact   = (int) $input->getOption('min-impact');

        /**
         * AI cost report window configuration.
         *
         * We keep this here (instead of inside renderMarkdown) so JSON output also contains
         * the same executive summary inputs.
         *
         * CLI options:
         * - --day=YYYY-MM-DD (default: today)
         * - --period=daily|weekly|monthly (default: daily)
         */
        $dayOpt = (string) $input->getOption('day');
        $period = strtolower((string) $input->getOption('period'));
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $period = 'daily';
        }

        // Window sizes: daily=1, weekly=7, monthly=30
        $windowDays = match ($period) {
            'weekly' => 7,
            'monthly' => 30,
            default => 1,
        };

        // Guard against invalid day format (do not fail hard)
        $endDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayOpt) ? $dayOpt : date('Y-m-d');

        // 1) Collect tracked methods
        $tracked = $this->collectTrackedMethods($nsPrefix);

        // 2) Enrich with usage counters + compute impact + init stable AI fields
        foreach ($tracked as &$r) {
            $r['seen']  = $this->usage->has($r['key']);
            $r['count'] = $this->usage->get($r['key']);

            $w = (int) ($r['weight'] ?? 1);
            if ($w < 1) {
                $w = 1;
            }

            $r['weight'] = $w;
            $r['impact'] = (int) $r['count'] * $w;

            // Stable AI fields (default 0) so renderers can rely on keys being present.
            $r['ai_requests'] = 0;
            $r['ai_tokens_in'] = 0;
            $r['ai_tokens_out'] = 0;
            $r['ai_tokens_total'] = 0;
            $r['ai_cost_eur'] = 0.0;
            $r['ai_errors'] = 0;
            $r['ai_cache_hits'] = 0;
            $r['ai_latency_ms_avg'] = 0;

            // Convenience KPIs
            $r['ai_eur_per_request'] = 0.0;
            $r['ai_eur_per_impact'] = 0.0;
        }
        unset($r);

        /**
         * 3) Load AI cost pack (current + previous + delta) + join by usage_key.
         *
         * Important:
         * - This uses the same usage_key as TrackUsage/UsageTracker.
         * - The join is "best-effort": if no aggregate exists for a key, values remain 0.
         * - We load current + previous window to compute deltas for the executive summary.
         *
         * Safety:
         * - If AI cost is not configured / cache is empty, the report still renders (AI=0, meta->aiCost.enabled=false).
         */
        $aiAvailable = true;
        $aiError = null;

        $aiCurrentTotals = [
            'requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cost_eur' => 0.0,
            'errors' => 0,
            'cache_hits' => 0,
            'latency_ms_sum' => 0,
        ];
        $aiPrevTotals = $aiCurrentTotals;

        $aiDeltaTotals = [
            'requests' => ['abs' => 0, 'pct' => null],
            'total_tokens' => ['abs' => 0, 'pct' => null],
            'cost_eur' => ['abs' => 0.0, 'pct' => null],
            'errors' => ['abs' => 0, 'pct' => null],
            'avg_latency_ms' => ['abs' => 0, 'pct' => null],
        ];

        /** @var array<string, array> $aiByUsageKey */
        $aiByUsageKey = [];

        try {
            $pack = $this->aiCostReader->loadWithPreviousAndDelta($endDay, $windowDays);

            $aiCurrentTotals = (array) ($pack['current']['totals'] ?? $aiCurrentTotals);
            $aiPrevTotals    = (array) ($pack['previous']['totals'] ?? $aiPrevTotals);
            $aiDeltaTotals   = (array) ($pack['delta_totals'] ?? $aiDeltaTotals);
            $aiByUsageKey    = (array) ($pack['current']['by_usage_key'] ?? []);
        } catch (\Throwable $e) {
            $aiAvailable = false;
            $aiError = $e->getMessage();
            $aiByUsageKey = [];
        }

        // Join AI aggregates into tracked rows
        foreach ($tracked as &$r) {
            $key = (string) ($r['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $a = $aiByUsageKey[$key] ?? null;
            if (!is_array($a)) {
                continue;
            }

            $req = (int) ($a['requests'] ?? 0);
            $latSum = (int) ($a['latency_ms_sum'] ?? 0);

            $r['ai_requests'] = $req;
            $r['ai_tokens_in'] = (int) ($a['input_tokens'] ?? 0);
            $r['ai_tokens_out'] = (int) ($a['output_tokens'] ?? 0);
            $r['ai_tokens_total'] = (int) ($a['total_tokens'] ?? 0);
            $r['ai_cost_eur'] = (float) ($a['cost_eur'] ?? 0.0);
            $r['ai_errors'] = (int) ($a['errors'] ?? 0);
            $r['ai_cache_hits'] = (int) ($a['cache_hits'] ?? 0);

            // Derived KPIs (safe division)
            $r['ai_latency_ms_avg'] = $req > 0 ? (int) round($latSum / $req) : 0;
            $r['ai_eur_per_request'] = $req > 0 ? ((float) $r['ai_cost_eur'] / $req) : 0.0;

            $impact = (int) ($r['impact'] ?? 0);
            $r['ai_eur_per_impact'] = $impact > 0 ? ((float) $r['ai_cost_eur'] / $impact) : 0.0;
        }
        unset($r);

        // 4) Usage totals (tracked, unfiltered)
        $totalUsage = 0;
        $totalImpact = 0;
        foreach ($tracked as $r) {
            $totalUsage += (int) ($r['count'] ?? 0);
            $totalImpact += (int) ($r['impact'] ?? 0);
        }

        // AI executive KPIs (current window)
        $aiAvgLatencyMs = ((int) ($aiCurrentTotals['requests'] ?? 0)) > 0
            ? (int) round(((int) ($aiCurrentTotals['latency_ms_sum'] ?? 0)) / (int) ($aiCurrentTotals['requests'] ?? 1))
            : 0;

        $aiErrorRate = ((int) ($aiCurrentTotals['requests'] ?? 0)) > 0
            ? ((int) ($aiCurrentTotals['errors'] ?? 0) / (int) ($aiCurrentTotals['requests'] ?? 1))
            : 0.0;

        // 5) Optional min-impact filter (applies to all sections & cost-driver ranking)
        $filtered = $tracked;
        if ($minImpact > 0) {
            $filtered = array_values(array_filter(
                $filtered,
                static fn(array $r): bool => ((int) ($r['impact'] ?? 0) >= $minImpact)
            ));
        }

        // 6) Sections
        // Top: impact DESC, then count DESC, then key ASC
        $top = $filtered;
        usort($top, static function (array $a, array $b): int {
            $c = ((int) ($b['impact'] ?? 0) <=> (int) ($a['impact'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            $c = ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });
        $top = array_slice($top, 0, $topN);

        // Unused: count <= unusedMax (with "never_seen" emphasis)
        $unused = array_values(array_filter(
            $filtered,
            static fn(array $r): bool => ((int) ($r['count'] ?? 0) <= $unusedMax)
        ));
        usort($unused, static function (array $a, array $b): int {
            // never_seen first
            $sa = ($a['seen'] ?? false) ? 1 : 0;
            $sb = ($b['seen'] ?? false) ? 1 : 0;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            // then impact ASC, then count ASC
            $c = ((int) ($a['impact'] ?? 0) <=> (int) ($b['impact'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            return ((int) ($a['count'] ?? 0) <=> (int) ($b['count'] ?? 0));
        });

        // Low: unusedMax < count <= lowMax
        $low = array_values(array_filter($filtered, static fn(array $r): bool =>
            ((int) ($r['count'] ?? 0) > $unusedMax) && ((int) ($r['count'] ?? 0) <= $lowMax)
        ));
        usort($low, static function (array $a, array $b): int {
            $c = ((int) ($b['impact'] ?? 0) <=> (int) ($a['impact'] ?? 0));
            if ($c !== 0) {
                return $c;
            }
            return ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0));
        });

        // Critical unused: weight >= criticalMin AND count <= unusedMax
        $criticalUnused = array_values(array_filter($filtered, static fn(array $r): bool =>
            ((int) ($r['weight'] ?? 1) >= $criticalMin) && ((int) ($r['count'] ?? 0) <= $unusedMax)
        ));
        usort($criticalUnused, static fn(array $a, array $b): int => ((int) ($b['weight'] ?? 1) <=> (int) ($a['weight'] ?? 1)));

        // Attention: low usage but relevant (weight >= attentionW), NOT unused
        $attention = array_values(array_filter($filtered, static fn(array $r): bool =>
            ((int) ($r['count'] ?? 0) > $unusedMax) &&
            ((int) ($r['count'] ?? 0) <= $lowMax) &&
            ((int) ($r['weight'] ?? 1) >= $attentionW)
        ));
        usort($attention, static fn(array $a, array $b): int => ((int) ($b['impact'] ?? 0) <=> (int) ($a['impact'] ?? 0)));

        // Executive cost drivers: Top 3 by EUR (within filtered scope)
        $byCost = $filtered;
        usort($byCost, static fn(array $a, array $b): int => ((float) ($b['ai_cost_eur'] ?? 0.0)) <=> ((float) ($a['ai_cost_eur'] ?? 0.0)));
        $summaryTop3Cost = array_slice($byCost, 0, 3);

        // Executive Top3 by impact
        $summaryTop3 = array_slice($top, 0, 3);

        $meta = [
            'generatedAt'     => date('c'),
            'namespace'       => $nsPrefix,
            'topN'            => $topN,
            'lowMax'          => $lowMax,
            'unusedMax'       => $unusedMax,
            'criticalMin'     => $criticalMin,
            'attentionWeight' => $attentionW,
            'minImpact'       => $minImpact,
            'trackedCount'    => count($tracked),
            'totalUsage'      => $totalUsage,
            'totalImpact'     => $totalImpact,
            'sortTopBy'       => 'impact_desc',
            'impactFormula'   => 'impact = usage_count * weight',

            // AI Cost meta (Variant 1)
            'aiCost' => [
                'enabled' => $aiAvailable,
                'error' => $aiError,
                'period' => $period,
                'endDay' => $endDay,
                'windowDays' => $windowDays,
                'totals_current' => $aiCurrentTotals,
                'totals_previous' => $aiPrevTotals,
                'delta_totals' => $aiDeltaTotals,
                'avg_latency_ms_current' => $aiAvgLatencyMs,
                'error_rate_current' => $aiErrorRate,
            ],

            // Executive summary helpers
            'summary' => [
                'top3'           => $summaryTop3,
                'top3Cost'       => $summaryTop3Cost,
                'attention'      => $attention,
                'criticalUnused' => $criticalUnused,
            ],
        ];

        $payload = [
            'meta' => $meta,
            'top' => $top,
            'low' => $low,
            'unused' => $unused,
            'criticalUnused' => $criticalUnused,

            // Convenience: a single joined view for BI/export consumers
            'all' => $filtered,
        ];

        $content = match ($format) {
            'json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            default => $this->renderMarkdown($meta, $summaryTop3, $summaryTop3Cost, $attention, $top, $low, $unused, $criticalUnused),
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
     * Collect all unique TrackUsage entry points from service classes.
     *
     * - Scans src/Service and reflects methods.
     * - Picks methods annotated with #[TrackUsage].
     * - Captures key, weight and location (class + method).
     * - Deduplicates by key (last writer wins in current implementation).
     *
     * @param string $namespacePrefix Namespace prefix used to build FQCNs from file paths.
     *
     * @return array<int, array{
     *   key:string,
     *   weight:int,
     *   class:string,
     *   method:string,
     *   seen?:bool,
     *   count?:int,
     *   impact?:int,
     *   ai_requests?:int,
     *   ai_tokens_in?:int,
     *   ai_tokens_out?:int,
     *   ai_tokens_total?:int,
     *   ai_cost_eur?:float,
     *   ai_errors?:int,
     *   ai_cache_hits?:int,
     *   ai_latency_ms_avg?:int,
     *   ai_eur_per_request?:float,
     *   ai_eur_per_impact?:float
     * }>
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

        // unique by key (prevents double counting if keys are reused accidentally)
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string) $r['key']] = $r;
        }
        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * Discover service classes by scanning src/Service and mapping paths to class names.
     *
     * @param string $namespacePrefix Namespace prefix (default App\\Service\\).
     *
     * @return array<int, string> Fully qualified class names.
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
     * Render the report as Markdown with a meta header, executive summary and tables per section.
     *
     * Content goals:
     * - Human-readable executive summary (usage + AI cost KPIs + deltas).
     * - Two "Top 3" snippets: by impact and by AI cost.
     * - Full "Top" table including AI columns, to prioritize work by operational and financial impact.
     */
    private function renderMarkdown(
        array $meta,
        array $summaryTop3,
        array $summaryTop3Cost,
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

        // AI Cost block
        $ai = (array) ($meta['aiCost'] ?? []);
        $md[] = '### AI Cost (rolling window)';
        $md[] = '';
        if (!(bool) ($ai['enabled'] ?? false)) {
            $err = (string) ($ai['error'] ?? 'unbekannt');
            $md[] = sprintf('_AI Cost nicht verfügbar/aktiv._ Fehler: `%s`', $err);
            $md[] = '';
        } else {
            $period = (string) ($ai['period'] ?? 'daily');
            $endDay = (string) ($ai['endDay'] ?? '');
            $days = (int) ($ai['windowDays'] ?? 1);

            $cur = (array) ($ai['totals_current'] ?? []);
            $delta = (array) ($ai['delta_totals'] ?? []);

            $curReq = (int) ($cur['requests'] ?? 0);
            $curTok = (int) ($cur['total_tokens'] ?? 0);
            $curEur = (float) ($cur['cost_eur'] ?? 0.0);
            $curErr = (int) ($cur['errors'] ?? 0);
            $avgMs = (int) ($ai['avg_latency_ms_current'] ?? 0);
            $errRate = (float) ($ai['error_rate_current'] ?? 0.0);

            $dEurAbs = (float) (($delta['cost_eur']['abs'] ?? 0.0));
            $dEurPct = $delta['cost_eur']['pct'] ?? null;

            $dReqAbs = (int) (($delta['requests']['abs'] ?? 0));
            $dReqPct = $delta['requests']['pct'] ?? null;

            $fmtPct = static function ($v): string {
                if ($v === null) {
                    return 'n/a';
                }
                return number_format((float) $v * 100, 1, '.', '') . '%';
            };

            $md[] = sprintf('- Window: `%s` (%d Tage), End: `%s`', $period, $days, $endDay);
            $md[] = sprintf('- Requests: `%d` (Δ %d / %s)', $curReq, $dReqAbs, $fmtPct($dReqPct));
            $md[] = sprintf('- Tokens total: `%d`', $curTok);
            $md[] = sprintf(
                '- Cost EUR: `%s` (Δ %s / %s)',
                number_format($curEur, 6, '.', ''),
                number_format($dEurAbs, 6, '.', ''),
                $fmtPct($dEurPct)
            );
            $md[] = sprintf('- Errors: `%d` | Error rate: `%s`', $curErr, number_format($errRate * 100, 2, '.', '') . '%');
            $md[] = sprintf('- Avg latency: `%d ms`', $avgMs);
            $md[] = '';

            // Executive assessment (short, actionable, management-ready)
            $md[] = 'Einschätzung:';
            if ($curReq === 0) {
                $md[] = '- Keine AI Calls im gewählten Fenster. Für Trend/Delta bitte realen Traffic erzeugen.';
            } else {
                $eurPerReq = $curReq > 0 ? ($curEur / $curReq) : 0.0;
                $md[] = sprintf('- Aktuell liegt ihr bei ca. `%s EUR/Request` im %s-Fenster.', number_format($eurPerReq, 6, '.', ''), $period);
                $md[] = '- Kosten sind aktuell nicht der Engpass; wichtiger sind Stabilität (Error-Rate) und Performance (Latenz).';
                if ($curErr > 0) {
                    $md[] = '- Es gibt Fehler im Fenster. Prüft die Error-Codes/Provider-Logs; Fehler treiben Latenz und können Retries verursachen.';
                }
                if ($avgMs > 5000) {
                    $md[] = '- Ø Latenz ist hoch (>5s). Kandidaten: Netzwerk, Provider-Rate-Limits, Retries, oder zu große Prompts.';
                }
            }
            $md[] = '';
        }

        // Top 3 by impact (with AI columns)
        $md[] = '### Top 3 (by impact)';
        $md[] = '';
        if ($summaryTop3 === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | AI Req | EUR | EUR/Req | Err | Avg ms | Key | Location |';
            $md[] = '|------:|------:|------:|------:|---:|---:|---:|------:|-----|----------|';
            foreach ($summaryTop3 as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | %d | %s | %s | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (int) ($r['ai_requests'] ?? 0),
                    number_format((float) ($r['ai_cost_eur'] ?? 0.0), 6, '.', ''),
                    number_format((float) ($r['ai_eur_per_request'] ?? 0.0), 6, '.', ''),
                    (int) ($r['ai_errors'] ?? 0),
                    (int) ($r['ai_latency_ms_avg'] ?? 0),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        // Top 3 by AI cost EUR
        $md[] = '### Top 3 (by AI cost EUR)';
        $md[] = '';
        if ($summaryTop3Cost === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| EUR | AI Req | EUR/Req | Err | Avg ms | Key | Location |';
            $md[] = '|---:|---:|---:|---:|---:|-----|----------|';
            foreach ($summaryTop3Cost as $r) {
                $md[] = sprintf(
                    '| %s | %d | %s | %d | %d | `%s` | `%s::%s()` |',
                    number_format((float) ($r['ai_cost_eur'] ?? 0.0), 6, '.', ''),
                    (int) ($r['ai_requests'] ?? 0),
                    number_format((float) ($r['ai_eur_per_request'] ?? 0.0), 6, '.', ''),
                    (int) ($r['ai_errors'] ?? 0),
                    (int) ($r['ai_latency_ms_avg'] ?? 0),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        // Attention
        $md[] = '### Attention (low usage but relevant)';
        $md[] = '';
        if ($attention === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | AI Req | EUR | EUR/Req | Err | Avg ms | Key | Location |';
            $md[] = '|------:|------:|------:|------:|---:|---:|---:|------:|-----|----------|';
            foreach ($attention as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | %d | %s | %s | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (int) ($r['ai_requests'] ?? 0),
                    number_format((float) ($r['ai_cost_eur'] ?? 0.0), 6, '.', ''),
                    number_format((float) ($r['ai_eur_per_request'] ?? 0.0), 6, '.', ''),
                    (int) ($r['ai_errors'] ?? 0),
                    (int) ($r['ai_latency_ms_avg'] ?? 0),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        // Critical unused (executive)
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

        // Full tables
        $md[] = '## Top (by impact)';
        $md[] = '';
        if ($top === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | AI Req | EUR | EUR/Req | Err | Avg ms | Key | Location |';
            $md[] = '|------:|------:|------:|------:|---:|---:|---:|------:|-----|----------|';
            foreach ($top as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | %d | %s | %s | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (int) ($r['ai_requests'] ?? 0),
                    number_format((float) ($r['ai_cost_eur'] ?? 0.0), 6, '.', ''),
                    number_format((float) ($r['ai_eur_per_request'] ?? 0.0), 6, '.', ''),
                    (int) ($r['ai_errors'] ?? 0),
                    (int) ($r['ai_latency_ms_avg'] ?? 0),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        // Low usage
        $md[] = '## Low usage';
        $md[] = '';
        if ($low === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Impact | Count | Weight | AI Req | EUR | EUR/Req | Err | Avg ms | Key | Location |';
            $md[] = '|------:|------:|------:|------:|---:|---:|---:|------:|-----|----------|';
            foreach ($low as $r) {
                $md[] = sprintf(
                    '| %d | %d | %d | %d | %s | %s | %d | %d | `%s` | `%s::%s()` |',
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (int) ($r['ai_requests'] ?? 0),
                    number_format((float) ($r['ai_cost_eur'] ?? 0.0), 6, '.', ''),
                    number_format((float) ($r['ai_eur_per_request'] ?? 0.0), 6, '.', ''),
                    (int) ($r['ai_errors'] ?? 0),
                    (int) ($r['ai_latency_ms_avg'] ?? 0),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        // Unused
        $md[] = '## Unused';
        $md[] = '';
        if ($unused === []) {
            $md[] = '_Keine Einträge._';
        } else {
            $md[] = '| Status | Impact | Count | Weight | AI Req | EUR | EUR/Req | Err | Avg ms | Key | Location |';
            $md[] = '|--------|------:|------:|------:|------:|---:|---:|---:|------:|-----|----------|';
            foreach ($unused as $r) {
                $status = ($r['seen'] ?? false) ? 'seen' : 'never_seen';
                $md[] = sprintf(
                    '| %s | %d | %d | %d | %d | %s | %s | %d | %d | `%s` | `%s::%s()` |',
                    $status,
                    (int) ($r['impact'] ?? 0),
                    (int) ($r['count'] ?? 0),
                    (int) ($r['weight'] ?? 1),
                    (int) ($r['ai_requests'] ?? 0),
                    number_format((float) ($r['ai_cost_eur'] ?? 0.0), 6, '.', ''),
                    number_format((float) ($r['ai_eur_per_request'] ?? 0.0), 6, '.', ''),
                    (int) ($r['ai_errors'] ?? 0),
                    (int) ($r['ai_latency_ms_avg'] ?? 0),
                    (string) ($r['key'] ?? ''),
                    (string) ($r['class'] ?? ''),
                    (string) ($r['method'] ?? '')
                );
            }
        }
        $md[] = '';

        // Critical unused (full)
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

    /**
     * Ensure an output directory exists (mkdir -p behavior).
     */
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
