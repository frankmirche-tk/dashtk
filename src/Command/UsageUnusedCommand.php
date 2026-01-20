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

/**
 * UsageUnusedCommand
 *
 * Purpose:
 * - Identifies tracked methods (#[TrackUsage]) that are used rarely or not at all.
 * - Helps you spot:
 *   - dead code / legacy flows (never_seen)
 *   - features that exist but are hardly used (low count)
 *   - tracking gaps caused by TTL expiration or missing increments (depending on your setup)
 *
 * How it works:
 * - Scans service classes under src/Service (derived from the given namespace prefix).
 * - For each method declared in the class that has #[TrackUsage]:
 *   - reads the usage counter via UsageTracker::get($key)
 *   - checks whether the key exists at all via UsageTracker::has($key)
 *   - classifies the key as:
 *     - "never_seen": key does not exist in cache (was never incremented or has expired)
 *     - "seen": key exists (even if count is 0, depending on implementation/TTL)
 * - Emits rows when count <= --min threshold.
 *
 * Options:
 * - --min:
 *   - threshold: list entries with usage <= min
 *   - default: 0 (lists unused counters)
 * - --namespace:
 *   - namespace prefix used to map src/Service files to FQCNs
 *   - default: App\\Service\\
 * - --format:
 *   - "text" (default) or "json"
 *
 * Output / sorting:
 * - Rows are sorted to make "never_seen" stand out first, then by count ascending.
 * - Text output shows:
 *   - status (never_seen|seen)
 *   - count
 *   - usage key
 *   - class::method()
 *
 * Typical usage:
 * - List completely unused tracked methods (count <= 0):
 *   - php bin/console dashtk:usage:unused
 * - List "low usage" items (count <= 2):
 *   - php bin/console dashtk:usage:unused --min=2
 * - JSON output for automation/reporting:
 *   - php bin/console dashtk:usage:unused --format=json
 *
 * Important note about TTL:
 * - If your UsageTracker uses a TTL (e.g. 30 days) and keys are not incremented within that period,
 *   they may disappear from cache and show up as "never_seen" even though they were used historically.
 * - For long-term analytics you would persist counters to a database or export snapshots regularly.
 */
#[AsCommand(
    name: 'dashtk:usage:unused',
    description: 'Listet getrackte Methoden (TrackUsage) mit geringer oder keiner Nutzung.'
)]
final class UsageUnusedCommand extends Command
{
    /**
     * @param UsageTracker $usage Usage tracker cache abstraction.
     */
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    /**
     * Configure CLI options.
     *
     * - min: list entries where count <= min
     * - namespace: namespace prefix to scan (default App\\Service\\)
     * - format: text|json
     */
    protected function configure(): void
    {
        $this
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Minimum Count (<= min wird gelistet). Standard: 0', '0')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Ausgabeformat: text|json', 'text');
    }

    /**
     * Execute the "unused/low usage" scan.
     *
     * Steps:
     * 1) Discover classes under src/Service and reflect methods.
     * 2) Collect all #[TrackUsage] methods and read their counters/status.
     * 3) Filter rows by count <= min.
     * 4) Sort rows (never_seen first, then count ascending).
     * 5) Print as text or JSON.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS always (read-only operation).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $min = (int) $input->getOption('min');
        $nsPrefix = (string) $input->getOption('namespace');
        $format = strtolower((string) $input->getOption('format'));

        $classes = $this->discoverClasses($nsPrefix);

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
                $key = $inst->key;

                $seen  = $this->usage->has($key);
                $count = $this->usage->get($key);
                $status = $seen ? 'seen' : 'never_seen';

                if ($count <= $min) {
                    $rows[] = [
                        'status' => $status,
                        'count' => $count,
                        'key' => $key,
                        'class' => $class,
                        'method' => $m->getName(),
                    ];
                }
            }
        }

        usort($rows, static function (array $a, array $b): int {
            // never_seen zuerst
            $sa = ($a['status'] ?? '') === 'never_seen' ? 0 : 1;
            $sb = ($b['status'] ?? '') === 'never_seen' ? 0 : 1;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return ($a['count'] ?? 0) <=> ($b['count'] ?? 0);
        });

        if ($format === 'json') {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
            return Command::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln(sprintf('OK: Keine getrackten Methoden mit usage <= %d gefunden.', $min));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Getrackte Methoden mit usage <= %d:', $min));
        foreach ($rows as $r) {
            $output->writeln(sprintf(
                '%10s  %6d  %-30s  %s::%s()',
                $r['status'],
                $r['count'],
                $r['key'],
                $r['class'],
                $r['method']
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Discover service class names based on a namespace prefix and the src/Service directory.
     *
     * Implementation:
     * - Scans PHP files under src/Service recursively.
     * - Builds a class name: $namespacePrefix + relative path (slashes -> backslashes, .php removed).
     *
     * @param string $namespacePrefix Namespace prefix (default: App\\Service\\).
     *
     * @return array<int, string> Fully qualified class names (sorted ASC).
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
}
