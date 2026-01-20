<?php

declare(strict_types=1);

namespace App\Command;

use App\Attribute\TrackUsage;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * UsageLintCommand
 *
 * Purpose:
 * - Static "lint" for your usage tracking convention:
 *   every method annotated with #[TrackUsage(key, weight)] should also call
 *   UsageTracker->increment(key) inside its method body.
 *
 * Why it matters:
 * - TrackUsage is a declaration ("this method should be tracked"),
 *   but the counter only increases if increment() is actually called.
 * - This command prevents silent tracking gaps, especially after refactors.
 *
 * How it works (high level):
 * - Scans service classes under src/Service (derived from the provided namespace prefix).
 * - For each method declared in the class:
 *   - if it has #[TrackUsage], the method's source code is extracted by line range
 *   - regex checks are performed on the source to find ->increment(...) calls
 *   - reports errors/warnings based on strictness and detectability
 *
 * What is checked:
 * 1) TrackUsage weight validity:
 *    - must be between 1 and 10 (inclusive)
 * 2) Strict mode rules (--strict):
 *    - only public methods may be tracked
 *    - exactly one increment() match is expected
 *    - variable/dynamic keys are treated as errors if an increment() exists but cannot be matched
 * 3) increment() key matching:
 *    - counts exact matches for:
 *      - ->increment('literal.key')
 *      - ->increment("literal.key")
 *      - ->increment(self::CONST) or ->increment(static::CONST)
 *        if the referenced constant exists on the class and resolves to the same literal key
 *
 * What is NOT reliably supported:
 * - Dynamic keys are intentionally not counted as matches:
 *   - ->increment($key)
 *   - ->increment($a . $b)
 *   - ->increment(sprintf(...))
 * - Those patterns are considered "not safely checkable" and will:
 *   - produce an error in strict mode (if an increment() exists but cannot be matched)
 *   - (intended) produce a warning in non-strict mode (see note below)
 *
 * Output / exit behavior:
 * - Prints WARN section if warnings exist.
 * - Prints FAIL section if errors exist.
 * - Exit code is FAILURE if:
 *   - errors exist, or
 *   - --fail-on-warn is enabled and warnings exist, or
 *   - --strict is enabled (it forces fail-on-warn behavior)
 *
 * CI usage:
 * - Recommended in CI as a gate:
 *   - php bin/console dashtk:usage:lint --strict
 *
 * Important note about current behavior:
 * - The code path "non-strict: warn on dynamic increment" is currently unreachable because
 *   the earlier "if ($countMatch === 0) { ... $errors[] ... continue; }" returns an error
 *   before the later warning branch can run.
 * - If you want non-strict mode to allow dynamic keys with only warnings, you should move
 *   the warning logic into the $countMatch === 0 branch (instead of always erroring there).
 */
#[AsCommand(
    name: 'dashtk:usage:lint',
    description: 'Lint: Prüft, ob TrackUsage-Methoden auch UsageTracker->increment(...) aufrufen.'
)]
final class UsageLintCommand extends Command
{
    /**
     * Configure lint behavior.
     *
     * - namespace: namespace prefix used to map scanned files to class names
     * - fail-on-warn: treat warnings as failure (exit code 1)
     * - strict:
     *   - enables CI-grade rules (public-only, exactly one increment, no variable key)
     *   - implicitly enables fail-on-warn
     */
    protected function configure(): void
    {
        $this
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')
            ->addOption('fail-on-warn', null, InputOption::VALUE_NONE, 'Behandelt Warnungen als Fehler (Exit Code 1)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Strict mode: public-only, genau 1 increment(), kein variable key');
    }

    /**
     * Execute the lint scan.
     *
     * Steps:
     * 1) Scan classes under src/Service and reflect methods.
     * 2) For methods annotated with #[TrackUsage]:
     *    - validate weight range
     *    - optionally enforce "public-only" in strict mode
     *    - extract source by file line ranges and search for increment() calls matching the key
     * 3) Print warnings/errors and return corresponding exit code.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS if lint passes, otherwise Command::FAILURE.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nsPrefix   = (string) $input->getOption('namespace');
        $failOnWarn = (bool) $input->getOption('fail-on-warn');
        $strict     = (bool) $input->getOption('strict');

        // In CI ist strict typischerweise "hart"
        if ($strict) {
            $failOnWarn = true;
        }

        $classes = $this->discoverClasses($nsPrefix);

        $errors = [];
        $warnings = [];

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
                $key = (string) $inst->key;

                // TrackUsage muss ein gültiges weight liefern (1..10)
                // Falls TrackUsage noch kein weight hat, solltet ihr dort default=1 setzen.
                $weight = (int) ($inst->weight ?? 1);

                if ($weight < 1 || $weight > 10) {
                    $errors[] = sprintf(
                        '%s::%s(): TrackUsage("%s") hat ungültiges weight=%d (erlaubt: 1..10).',
                        $class,
                        $m->getName(),
                        $key,
                        $weight
                    );
                    continue;
                }

                if ($strict && !$m->isPublic()) {
                    $errors[] = sprintf(
                        '%s::%s(): TrackUsage("%s") ist nur auf public Methoden erlaubt.',
                        $class,
                        $m->getName(),
                        $key
                    );
                    continue;
                }

                $src = $this->getMethodSource($m);
                if ($src === null) {
                    $warnings[] = sprintf('%s::%s(): Quelle nicht lesbar (kein File/LineInfo).', $class, $m->getName());
                    continue;
                }

                $countMatch = $this->countIncrementForKey($ref, $src, $key);
                $hasAnyIncrement = $this->hasAnyIncrementCall($src);

                if ($countMatch === 0) {
                    if ($strict && $hasAnyIncrement) {
                        // increment() existiert, aber nicht mit key/const match => wahrscheinlich variable / dynamic
                        $errors[] = sprintf(
                            '%s::%s(): TrackUsage("%s") aber increment() wird nicht mit einem prüfbaren Key aufgerufen (vermutlich variable/dynamic).',
                            $class,
                            $m->getName(),
                            $key
                        );
                    } else {
                        $errors[] = sprintf(
                            '%s::%s(): TrackUsage("%s") aber kein increment() mit passendem Key gefunden.',
                            $class,
                            $m->getName(),
                            $key
                        );
                    }
                    continue;
                }

                if ($strict && $countMatch > 1) {
                    $errors[] = sprintf(
                        '%s::%s(): TrackUsage("%s") aber increment() wurde %d-mal gefunden (erwartet: genau 1).',
                        $class,
                        $m->getName(),
                        $key,
                        $countMatch
                    );
                    continue;
                }

                // Non-strict: wenn increment() zwar vorkommt, aber nicht matchbar ist, nur warnen.
                if (!$strict && $countMatch === 0 && $hasAnyIncrement) {
                    $warnings[] = sprintf(
                        '%s::%s(): increment() gefunden, aber Key "%s" nicht sicher prüfbar (variable/dynamic).',
                        $class,
                        $m->getName(),
                        $key
                    );
                }
            }
        }

        if ($warnings !== []) {
            $output->writeln('<comment>WARN:</comment>');
            foreach ($warnings as $w) {
                $output->writeln('  - ' . $w);
            }
            $output->writeln('');
        }

        if ($errors !== []) {
            $output->writeln('<error>FAIL:</error> Usage-Lint Fehler gefunden:');
            foreach ($errors as $e) {
                $output->writeln('  - ' . $e);
            }
            return Command::FAILURE;
        }

        if ($failOnWarn && $warnings !== []) {
            $output->writeln('<error>FAIL:</error> fail-on-warn aktiv und es gibt Warnungen.');
            return Command::FAILURE;
        }

        $output->writeln('<info>OK</info> Usage-Lint bestanden.');
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
     * - The command relies on class_exists() to skip non-autoloadable results safely.
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
     * Extract the method's source code by slicing the declaring file by start/end line numbers.
     *
     * This is a best-effort "static inspection" approach (fast, no AST parser required).
     *
     * @param ReflectionMethod $m Reflected method.
     *
     * @return string|null Method source code or null if file/line information is not available.
     */
    private function getMethodSource(ReflectionMethod $m): ?string
    {
        $file  = $m->getFileName();
        $start = $m->getStartLine();
        $end   = $m->getEndLine();

        if (!is_string($file) || $file === '' || $start === false || $end === false) {
            return null;
        }
        if (!is_file($file)) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $startIdx = max(0, $start - 1);
        $endIdx   = min(count($lines) - 1, $end - 1);

        $slice = array_slice($lines, $startIdx, $endIdx - $startIdx + 1);
        return implode("\n", $slice);
    }

    /**
     * Detect whether the method source contains any "->increment(" call at all (independent of key).
     *
     * @param string $methodSource Method source code.
     *
     * @return bool True if an increment() call is present, otherwise false.
     */
    private function hasAnyIncrementCall(string $methodSource): bool
    {
        $src = preg_replace('~\s+~', ' ', $methodSource) ?? $methodSource;
        return preg_match('~->\s*increment\s*\(~i', $src) === 1;
    }

    /**
     * Count increment() calls that can be verified to match the given key.
     *
     * Supported patterns:
     * - ->increment('literal.key')
     * - ->increment("literal.key")
     * - ->increment(self::CONST) / ->increment(static::CONST)
     *   - only if the constant exists on the class and its value equals $key
     *
     * Not supported (intentionally not counted as match):
     * - variable/dynamic keys:
     *   - ->increment($x)
     *   - ->increment($a . $b)
     *   - ->increment(sprintf(...))
     *
     * @param ReflectionClass $ref          Reflected class (for constant resolution).
     * @param string          $methodSource Method source code.
     * @param string          $key          TrackUsage key to validate.
     *
     * @return int Number of verified matching increment() calls.
     */
    private function countIncrementForKey(ReflectionClass $ref, string $methodSource, string $key): int
    {
        // Whitespace normalisieren, damit Regex stabiler ist
        $src = preg_replace('~\s+~', ' ', $methodSource) ?? $methodSource;

        $count = 0;

        // 1) Direct: ->increment('key') / ->increment("key")
        $patternDirect = '~->\s*increment\s*\(\s*[\'"]' . preg_quote($key, '~') . '[\'"]~i';
        if (preg_match_all($patternDirect, $src, $mm) > 0) {
            $count += (int) preg_match_all($patternDirect, $src);
        }

        // 2) Const: ->increment(self::CONST) / static::CONST
        $patternConst = '~->\s*increment\s*\(\s*(self|static)::([A-Z0-9_]+)\b~';
        if (preg_match_all($patternConst, $src, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $hit) {
                $constName = $hit[2] ?? null;
                if (!is_string($constName) || $constName === '') {
                    continue;
                }
                if (!$ref->hasConstant($constName)) {
                    continue;
                }
                $value = $ref->getConstant($constName);
                if (is_string($value) && $value === $key) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
