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

#[AsCommand(
    name: 'dashtk:usage:lint',
    description: 'Lint: Prüft, ob TrackUsage-Methoden auch UsageTracker->increment(...) aufrufen.'
)]
final class UsageLintCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')
            ->addOption('fail-on-warn', null, InputOption::VALUE_NONE, 'Behandelt Warnungen als Fehler (Exit Code 1)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Strict mode: public-only, genau 1 increment(), kein variable key')
        ;
    }

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
     * @return array<int,string>
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
     * Extrahiert den Methoden-Quelltext grob über File + Start/End Line.
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
     * Erkennt, ob irgendwo ein increment(...) call im Methodentext vorkommt (egal welcher key).
     */
    private function hasAnyIncrementCall(string $methodSource): bool
    {
        $src = preg_replace('~\s+~', ' ', $methodSource) ?? $methodSource;
        return preg_match('~->\s*increment\s*\(~i', $src) === 1;
    }

    /**
     * Zählt passende increment()-Aufrufe für einen Key.
     *
     * Unterstützt:
     * - ->increment('key') / ->increment("key")
     * - ->increment(self::CONST) / static::CONST, wenn CONST in der Klasse existiert und == key ist
     *
     * Hinweis: variable/dynamic keys (->increment($x), ->increment($a.$b), ->increment(sprintf(...))) werden NICHT als match gezählt.
     */
    private function countIncrementForKey(ReflectionClass $ref, string $methodSource, string $key): int
    {
        // Whitespace normalisieren, damit Regex stabiler ist
        $src = preg_replace('~\s+~', ' ', $methodSource) ?? $methodSource;

        $count = 0;

        // 1) Direkt: ->increment('key') / ->increment("key")
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
