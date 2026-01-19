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
    name: 'dashtk:usage:unused',
    description: 'Listet getrackte Methoden (TrackUsage) mit geringer oder keiner Nutzung.'
)]
final class UsageUnusedCommand extends Command
{
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Minimum Count (<= min wird gelistet). Standard: 0', '0')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace-Prefix zum Scannen', 'App\\Service\\')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Ausgabeformat: text|json', 'text')
        ;
    }

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
}
