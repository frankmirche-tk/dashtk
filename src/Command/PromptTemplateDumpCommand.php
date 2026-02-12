<?php
#bin/console app:prompts:dump-template NewsletterCreatePrompt.config
#bin/console app:prompts:dump-template NewsletterCreatePrompt.config --part=system | head -n 60
#bin/console app:prompts:dump-template NewsletterCreatePrompt.config --var=year=2026 --var=kw=7 --var=driveUrl=https://...
#bin/console app:prompts:dump-template NewsletterCreatePrompt.config --json



declare(strict_types=1);

namespace App\Command;

use App\Service\PromptTemplateLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:prompts:dump-template',
    description: 'Dump eines Prompt-Templates (SYSTEM/USER) inkl. Includes – optional mit Render-Variablen.'
)]
final class PromptTemplateDumpCommand extends Command
{
    public function __construct(
        private readonly PromptTemplateLoader $promptLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Prompt-Datei relativ zu src/Service/Prompts (z.B. NewsletterCreatePrompt.config)')
            ->addOption('part', null, InputOption::VALUE_REQUIRED, 'system|user|both', 'both')
            ->addOption('var', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Template-Variable key=value (mehrfach)', [])
            ->addOption('no-render', null, InputOption::VALUE_NONE, 'Keine Variablen rendern (rohe Templates ausgeben)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Ausgabe als JSON (maschinenlesbar)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string)$input->getArgument('file');
        $part = strtolower((string)$input->getOption('part'));
        $noRender = (bool)$input->getOption('no-render');
        $asJson = (bool)$input->getOption('json');

        if (!in_array($part, ['system', 'user', 'both'], true)) {
            $output->writeln('<error>Ungültiger Wert für --part. Erlaubt: system|user|both</error>');
            return Command::FAILURE;
        }

        $tpl = $this->promptLoader->load($file);
        $vars = $this->parseVars((array)$input->getOption('var'));

        $system = (string)($tpl['system'] ?? '');
        $user   = (string)($tpl['user'] ?? '');

        if (!$noRender && $vars !== []) {
            $system = $this->promptLoader->render($system, $vars);
            $user   = $this->promptLoader->render($user, $vars);
        }

        if ($asJson) {
            $payload = [
                'file' => $file,
                'baseDir' => $this->promptLoader->getBaseDir(),
                'part' => $part,
                'vars' => $vars,
                'system' => ($part === 'user') ? null : trim($system),
                'user' => ($part === 'system') ? null : trim($user),
            ];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        if ($part === 'system' || $part === 'both') {
            $output->writeln('===== SYSTEM (' . $file . ') =====');
            $output->writeln(trim($system) !== '' ? trim($system) : '<leer>');
            $output->writeln('');
        }

        if ($part === 'user' || $part === 'both') {
            $output->writeln('===== USER (' . $file . ') =====');
            $output->writeln(trim($user) !== '' ? trim($user) : '<leer>');
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /**
     * @param string[] $pairs
     * @return array<string,string>
     */
    private function parseVars(array $pairs): array
    {
        $vars = [];
        foreach ($pairs as $pair) {
            $pair = (string)$pair;
            $pos = strpos($pair, '=');
            if ($pos === false) { continue; }

            $key = trim(substr($pair, 0, $pos));
            $val = substr($pair, $pos + 1);

            if ($key !== '') {
                $vars[$key] = $val;
            }
        }
        return $vars;
    }
}
