<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DocumentLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsCommand(name: 'app:doc:extract')]
final class DocExtractCommand extends Command
{
    public function __construct(private readonly DocumentLoader $loader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'local|drive')
            ->addArgument('value', InputArgument::REQUIRED, 'Pfad zur Datei oder Drive FileId');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string)$input->getArgument('type');
        $value = (string)$input->getArgument('value');

        if ($type === 'drive') {
            $doc = $this->loader->extractFromDrive($value);
        } else {
            if (!is_file($value)) {
                $output->writeln('<error>Datei nicht gefunden</error>');
                return Command::FAILURE;
            }

            // UploadedFile simulieren
            $file = new UploadedFile($value, basename($value), null, null, true);
            $doc = $this->loader->extractFromUploadedFile($file);
        }

        $output->writeln('method: ' . $doc->method);
        $output->writeln('needsOcr: ' . ($doc->needsOcr ? 'true' : 'false'));
        $output->writeln('confidence: ' . $doc->confidence);
        if ($doc->warnings) {
            $output->writeln('warnings:');
            foreach ($doc->warnings as $w) {
                $output->writeln(' - ' . $w);
            }
        }

        $output->writeln("\n--- TEXT (first 1000 chars) ---\n");
        $output->writeln(mb_substr($doc->text, 0, 1000));

        return Command::SUCCESS;
    }
}
