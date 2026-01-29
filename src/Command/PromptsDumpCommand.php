<?php

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
    name: 'app:prompts:dump',
    description: 'Gibt einen Prompt (SYSTEM/USER) inkl. Includes aus – optional mit Render-Variablen.'
)]
final class PromptsDumpCommand extends Command
{
    public function __construct(
        private readonly PromptTemplateLoader $promptLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Prompt-Datei relativ zu src/Service/Prompts (z.B. KiChatBotPrompt.config)'
            )
            ->addOption(
                'part',
                null,
                InputOption::VALUE_REQUIRED,
                'Welche Teile ausgeben? system|user|both',
                'both'
            )
            ->addOption(
                'var',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Template-Variable setzen, Format: key=value (mehrfach möglich)'
            )
            ->addOption(
                'no-render',
                null,
                InputOption::VALUE_NONE,
                'Wenn gesetzt: keine Variablen rendern, rohe Templates ausgeben'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        $part = strtolower((string) $input->getOption('part'));
        $noRender = (bool) $input->getOption('no-render');

        if (!in_array($part, ['system', 'user', 'both'], true)) {
            $output->writeln('<error>Ungültiger Wert für --part. Erlaubt: system|user|both</error>');
            return Command::FAILURE;
        }

        $tpl = $this->promptLoader->load($file);

        $vars = $this->parseVars((array) $input->getOption('var'));

        $system = $tpl['system'] ?? '';
        $user   = $tpl['user'] ?? '';

        if (!$noRender && $vars !== []) {
            $system = $this->promptLoader->render($system, $vars);
            $user   = $this->promptLoader->render($user, $vars);
        }

        if ($part === 'system' || $part === 'both') {
            $output->writeln('===== SYSTEM (' . $file . ') =====');
            $output->writeln($system !== '' ? $system : '<leer>');
            $output->writeln('');
        }

        if ($part === 'user' || $part === 'both') {
            $output->writeln('===== USER (' . $file . ') =====');
            $output->writeln($user !== '' ? $user : '<leer>');
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /**
     * @param string[] $pairs
     * @return array<string, string>
     */
    private function parseVars(array $pairs): array
    {
        $vars = [];
        foreach ($pairs as $pair) {
            $pair = (string) $pair;
            $pos = strpos($pair, '=');
            if ($pos === false) {
                // ignorieren oder als Fehler behandeln – ich ignoriere still, damit es nicht nervt
                continue;
            }
            $key = trim(substr($pair, 0, $pos));
            $val = substr($pair, $pos + 1);
            if ($key !== '') {
                $vars[$key] = $val;
            }
        }
        return $vars;
    }
}
