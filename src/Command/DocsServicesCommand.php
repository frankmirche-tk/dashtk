<?php

declare(strict_types=1);

namespace App\Command;

use App\Attribute\TrackUsage;
use App\Service\UsageTracker;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * DocsServicesCommand
 *
 * Purpose:
 * - Generates a compact "living documentation" for application services by scanning PHP classes and
 *   extracting:
 *   - a class-level "Purpose" section from PHPDoc
 *   - method signatures (visibility/static/params/return)
 *   - a short one-line summary per method from PHPDoc
 *   - optional usage metadata derived from #[TrackUsage] and current counters from UsageTracker
 *
 * Intended output:
 * - Markdown (human-friendly) or JSON (machine-friendly).
 * - Either printed to STDOUT or written to a file (e.g. var/docs/services.md).
 *
 * How services are discovered:
 * - MVP approach: scans the filesystem directory "src/Service" (relative to this command)
 *   and derives class names by combining the given namespace prefix with the relative path.
 * - This is intentionally simple and fast, but assumes:
 *   - services live under src/Service
 *   - namespace prefix matches the directory structure (default "App\\Service\\")
 *
 * Purpose extraction rules:
 * - Prefers a PHPDoc block section that starts with "Purpose:" (case-insensitive).
 * - Captures subsequent non-empty lines until an empty line or an annotation line "@..." appears.
 * - Fallback: uses the first non-empty doc line (non-annotation) if no "Purpose:" block exists.
 *
 * Method documentation rules:
 * - Only includes methods declared in the scanned class (no inherited methods).
 * - Summary is extracted from the first non-empty, non-annotation line of the method PHPDoc.
 * - Method signature is rendered as a readable inline string (types + defaults if available).
 *
 * Usage integration:
 * - If --with-usage is enabled:
 *   - reads #[TrackUsage(key, weight)] attribute (if present)
 *   - reads current usage count from UsageTracker for the given key
 *   - computes "impact" = usageCount * max(weight, 1)
 * - If --only-tracked is enabled:
 *   - only methods with #[TrackUsage] are shown
 *
 * Sorting:
 * - If --with-usage is enabled:
 *   - tracked methods first
 *   - then by impact (DESC), usage (DESC), weight (DESC), name (ASC)
 * - Otherwise methods are returned in reflection order.
 *
 * Typical use cases:
 * - Generate service inventory for internal documentation.
 * - Identify "high impact" vs "low usage" methods (when combined with usage tracking).
 * - Provide a stable snapshot for audits, onboarding, and refactoring planning.
 */
#[AsCommand(
    name: 'dashtk:docs:services',
    description: 'Generiert eine kompakte Service-Dokumentation (Purpose + Methoden) aus PHPDoc.'
)]
final class DocsServicesCommand extends Command
{
    /**
     * @param UsageTracker $usage Usage counter storage used when --with-usage is enabled.
     */
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    /**
     * Configure CLI arguments and options.
     *
     * - namespace (argument): Namespace prefix used to map discovered files to class names.
     * - format: md|json
     * - output: optional output file path
     * - with-usage: include TrackUsage metadata, counters and impact score
     * - only-tracked: only include methods annotated with #[TrackUsage]
     */
    protected function configure(): void
    {
        $this
            ->addArgument('namespace', InputArgument::OPTIONAL, 'Namespace-Prefix zum Scannen', 'App\\Service\\')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Ausgabeformat: md|json', 'md')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Optional: Pfad in Datei schreiben (z.B. var/docs/services.md)')
            ->addOption('with-usage', null, InputOption::VALUE_NONE, 'Zeigt TrackUsage + Usage-Count, falls vorhanden')
            ->addOption('only-tracked', null, InputOption::VALUE_NONE, 'Zeigt nur Methoden mit #[TrackUsage(...)]');
    }

    /**
     * Execute the service documentation generation.
     *
     * Steps:
     * 1) Read options and discover service classes under src/Service.
     * 2) Build a structured array with class purpose and method metadata.
     * 3) Render to selected output format (md/json).
     * 4) Print to STDOUT or write to file if --output is provided.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on write/dir errors.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nsPrefix    = (string) $input->getArgument('namespace');
        $format      = strtolower((string) $input->getOption('format'));
        $outFile     = $input->getOption('output') ? (string) $input->getOption('output') : null;
        $withUsage   = (bool) $input->getOption('with-usage');
        $onlyTracked = (bool) $input->getOption('only-tracked');

        $classes = $this->discoverClasses($nsPrefix);

        $data = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);
            $classDoc = $ref->getDocComment() ?: '';

            $data[] = [
                'class' => $class,
                'purpose' => $this->extractPurpose($classDoc),
                'methods' => $this->extractMethods($ref, $withUsage, $onlyTracked),
            ];
        }

        $content = match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'md'   => $this->renderMarkdown($data, $withUsage),
            default => $this->renderMarkdown($data, $withUsage),
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
     * Discover service class names based on a namespace prefix and the src/Service directory.
     *
     * Implementation:
     * - Scans PHP files under src/Service recursively.
     * - Builds a class name: $namespacePrefix + relative path (slashes -> backslashes, .php removed).
     *
     * Caveats:
     * - Assumes that namespace prefix matches filesystem layout.
     * - Does not validate that the class is autoloadable beyond class_exists() checks.
     *
     * @param string $namespacePrefix Namespace prefix (default: "App\\Service\\").
     *
     * @return array<int, string> Fully qualified class names (sorted ASC).
     */
    private function discoverClasses(string $namespacePrefix): array
    {
        // MVP: bewusst simpel: Service-Namespace -> src/Service
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
     * Extract a class "purpose" string from a PHPDoc block.
     *
     * Priority:
     * 1) "Purpose:" block:
     *    - captures the line after "Purpose:" and subsequent doc lines
     *    - stops at an empty line or a line starting with "@"
     * 2) Fallback: first non-empty doc line that is not an annotation.
     *
     * @param string $doc Raw class doc comment (including /** ... *\/ markers).
     *
     * @return string Normalized purpose text (single line), or empty string if not available.
     */
    private function extractPurpose(string $doc): string
    {
        if ($doc === '') {
            return '';
        }

        $lines = preg_split('~\R~', $doc) ?: [];
        $capture = false;
        $buf = [];

        foreach ($lines as $line) {
            $line = trim($line, "/* \t");

            if ($line === '') {
                if ($capture) {
                    break;
                }
                continue;
            }

            if (preg_match('~^Purpose:\s*(.*)$~i', $line, $m)) {
                $capture = true;
                $first = trim($m[1]);
                if ($first !== '') {
                    $buf[] = $first;
                }
                continue;
            }

            if ($capture) {
                if (str_starts_with($line, '@')) {
                    break;
                }
                $buf[] = $line;
            }
        }

        if ($buf !== []) {
            return trim(preg_replace('~\s+~', ' ', implode(' ', $buf)) ?? '');
        }

        // Fallback: erste Doc-Zeile
        foreach ($lines as $line) {
            $line = trim($line, "/* \t");
            if ($line !== '' && !str_starts_with($line, '@')) {
                return $line;
            }
        }

        return '';
    }

    /**
     * Extract method metadata from a class reflection.
     *
     * Included:
     * - visibility/public|protected|private
     * - static flag
     * - formatted parameter string (incl. defaults)
     * - return type (if present)
     * - summary line extracted from method PHPDoc
     * - optional TrackUsage information (key, weight)
     * - optional usage numbers (usageCount, impact) if --with-usage is enabled
     *
     * Filtering:
     * - Only methods declared in the given class are considered.
     * - If $onlyTracked is true, only methods with #[TrackUsage] are included.
     *
     * @param ReflectionClass $ref        Reflected class.
     * @param bool            $withUsage  Whether to enrich results with usage counters and impact.
     * @param bool            $onlyTracked Whether to include only tracked methods.
     *
     * @return array<int, array{
     *   name:string,
     *   visibility:string,
     *   static:bool,
     *   params:string,
     *   return:string,
     *   summary:string,
     *   trackKey?:string|null,
     *   weight?:int|null,
     *   usageCount?:int|null,
     *   impact?:int|null
     * }>
     */
    private function extractMethods(ReflectionClass $ref, bool $withUsage, bool $onlyTracked): array
    {
        $methods = [];

        foreach ($ref->getMethods() as $m) {
            // Nur Methoden, die in der Klasse selbst deklariert sind
            if ($m->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            $trackKey = null;
            $weight = null;

            $attrs = $m->getAttributes(TrackUsage::class);
            if ($attrs !== []) {
                /** @var TrackUsage $inst */
                $inst = $attrs[0]->newInstance();
                $trackKey = $inst->key;
                $weight = $inst->weight;
            }

            if ($onlyTracked && $trackKey === null) {
                continue;
            }

            $usageCount = null;
            $impact = null;

            if ($withUsage && $trackKey !== null) {
                $usageCount = $this->usage->get($trackKey);

                $w = (int) ($weight ?? 1);
                if ($w < 1) {
                    $w = 1;
                }

                $impact = $usageCount * $w;
            }

            $row = [
                'name' => $m->getName(),
                'visibility' => $m->isPublic() ? 'public' : ($m->isProtected() ? 'protected' : 'private'),
                'static' => $m->isStatic(),
                'params' => $this->formatParams($m),
                'return' => $m->getReturnType() ? (string) $m->getReturnType() : '',
                'summary' => $this->extractSummary($m->getDocComment() ?: ''),
            ];

            if ($trackKey !== null) {
                $row['trackKey'] = $trackKey;
                $row['weight'] = (int) ($weight ?? 1);
            }

            if ($withUsage && $trackKey !== null) {
                $row['usageCount'] = (int) ($usageCount ?? 0);
                $row['impact'] = (int) ($impact ?? 0);
            }

            $methods[] = $row;
        }

        if ($withUsage) {
            usort($methods, static function (array $a, array $b): int {
                $aTracked = !empty($a['trackKey']);
                $bTracked = !empty($b['trackKey']);

                // tracked zuerst
                if ($aTracked !== $bTracked) {
                    return $aTracked ? -1 : 1;
                }

                // beide untracked: name ASC
                if (!$aTracked) {
                    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                }

                // beide tracked: impact DESC
                $ai = (int) ($a['impact'] ?? 0);
                $bi = (int) ($b['impact'] ?? 0);
                $c = $bi <=> $ai;
                if ($c !== 0) {
                    return $c;
                }

                // dann usage DESC
                $ac = (int) ($a['usageCount'] ?? 0);
                $bc = (int) ($b['usageCount'] ?? 0);
                $c = $bc <=> $ac;
                if ($c !== 0) {
                    return $c;
                }

                // dann weight DESC
                $aw = (int) ($a['weight'] ?? 1);
                $bw = (int) ($b['weight'] ?? 1);
                $c = $bw <=> $aw;
                if ($c !== 0) {
                    return $c;
                }

                return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
        }

        return $methods;
    }

    /**
     * Extract the first non-empty, non-annotation line from a doc comment.
     *
     * @param string $doc Raw doc comment.
     *
     * @return string Summary line or empty string if none found.
     */
    private function extractSummary(string $doc): string
    {
        if ($doc === '') {
            return '';
        }

        $lines = preg_split('~\R~', $doc) ?: [];
        foreach ($lines as $line) {
            $line = trim($line, "/* \t");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            return $line;
        }

        return '';
    }

    /**
     * Format method parameters into a compact readable signature string.
     *
     * Output example:
     * - "string $name, int $limit = 5, ?Foo $bar = null"
     *
     * @param ReflectionMethod $m Reflected method.
     *
     * @return string Rendered parameter list.
     */
    private function formatParams(ReflectionMethod $m): string
    {
        $parts = [];
        foreach ($m->getParameters() as $p) {
            $type = $p->getType() ? (string) $p->getType() . ' ' : '';
            $name = '$' . $p->getName();

            $default = '';
            if ($p->isDefaultValueAvailable()) {
                $default = ' = ' . $this->formatDefaultValue($p->getDefaultValue());
            }

            $parts[] = $type . $name . $default;
        }

        return implode(', ', $parts);
    }

    /**
     * Format a default parameter value for compact signature output.
     *
     * Rules:
     * - arrays are rendered as [] or [...] to avoid huge output
     * - strings are quoted
     * - scalars are rendered directly
     *
     * @param mixed $v Default value.
     *
     * @return string Rendered default value.
     */
    private function formatDefaultValue(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if ($v === true) {
            return 'true';
        }
        if ($v === false) {
            return 'false';
        }
        if (is_string($v)) {
            return '"' . $v . '"';
        }
        if (is_array($v)) {
            return $v === [] ? '[]' : '[...]';
        }

        return (string) $v;
    }

    /**
     * Render service documentation as Markdown.
     *
     * Structure:
     * - H1 title + short intro
     * - H2 per class
     * - Purpose line (if available)
     * - Bullet list of method signatures and summaries
     * - If usage is enabled: appends (track key, weight, usage, impact) metadata inline
     *
     * @param array<int, array{class:string, purpose:string, methods:array<int, array<string, mixed>>}> $data
     * @param bool $withUsage Whether to include usage details in the rendered output.
     *
     * @return string Markdown document.
     */
    private function renderMarkdown(array $data, bool $withUsage): string
    {
        $md = [];
        $md[] = '# Service Dokumentation';
        $md[] = '';
        $md[] = 'Generiert aus PHPDoc (Living Documentation).';
        $md[] = '';

        foreach ($data as $c) {
            $md[] = '## ' . $c['class'];
            if (($c['purpose'] ?? '') !== '') {
                $md[] = '';
                $md[] = '**Purpose:** ' . $c['purpose'];
            }
            $md[] = '';
            $md[] = '### Methoden';
            $md[] = '';

            foreach ($c['methods'] as $m) {
                $sig = sprintf(
                    '`%s%s %s(%s)%s`',
                    (string) $m['visibility'],
                    !empty($m['static']) ? ' static' : '',
                    (string) $m['name'],
                    (string) $m['params'],
                    ($m['return'] ?? '') !== '' ? ': ' . (string) $m['return'] : ''
                );

                $line = '- ' . $sig . (($m['summary'] ?? '') !== '' ? ' — ' . (string) $m['summary'] : '');

                if (!empty($m['trackKey'])) {
                    $line .= ' (track: ' . (string) $m['trackKey'];

                    if (isset($m['weight'])) {
                        $line .= ', weight: ' . (int) ($m['weight'] ?? 1);
                    }

                    if ($withUsage && array_key_exists('usageCount', $m)) {
                        $line .= ', usage: ' . (int) ($m['usageCount'] ?? 0);

                        if (array_key_exists('impact', $m)) {
                            $line .= ', impact: ' . (int) ($m['impact'] ?? 0);
                        }
                    }

                    $line .= ')';
                }

                $md[] = $line;
            }

            $md[] = '';
        }

        return implode("\n", $md);
    }

    /**
     * Ensure an output directory exists (mkdir -p behavior).
     *
     * @param string $dir Directory path.
     *
     * @return bool True if directory exists or was created successfully.
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
