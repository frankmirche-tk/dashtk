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

/**
 * Console Command: Prompts (SYSTEM/USER) aus Template-Dateien dumpen.
 *
 * Dieser Command lädt eine Prompt-Konfiguration (SYSTEM/USER) aus einem Template-File
 * unterhalb von `src/Service/Prompts` (über den PromptTemplateLoader),
 * löst dabei Includes auf und kann Variablen in das Template rendern.
 *
 * Typische Anwendungsfälle:
 * - Debugging: Welcher Prompt wird effektiv verwendet (inkl. Includes)?
 * - QA/Support: Vergleich verschiedener Prompt-Stände/Varianten
 * - Template-Entwicklung: Variablen ausprobieren, ohne Code auszuführen
 *
 * Ausgabe:
 * - SYSTEM, USER oder beide Teile (Standard: beide)
 * - Optional ohne Rendering (rohe Template-Ausgabe) via --no-render
 *
 * Beispiele:
 * - SYSTEM+USER:
 *   bin/console app:prompts:dump KiChatBotPrompt.config
 * - Nur SYSTEM:
 *   bin/console app:prompts:dump KiChatBotPrompt.config --part=system
 * - Mit Variablen:
 *   bin/console app:prompts:dump KiChatBotPrompt.config --var=shop=schop-lieblingsplatz.de --var=lang=de
 * - Ohne Rendering (rohe Templates):
 *   bin/console app:prompts:dump KiChatBotPrompt.config --no-render
 *
 * @see PromptTemplateLoader::load()
 * @see PromptTemplateLoader::render()
 */
#[AsCommand(
    name: 'app:prompts:dump',
    description: 'Gibt einen Prompt (SYSTEM/USER) inkl. Includes aus – optional mit Render-Variablen.'
)]
final class PromptsDumpCommand extends Command
{
    /**
     * @param PromptTemplateLoader $promptLoader Loader/Renderer für Prompt-Templates inkl. Includes.
     */
    public function __construct(
        private readonly PromptTemplateLoader $promptLoader,
    ) {
        parent::__construct();
    }

    /**
     * Definiert Argumente/Optionen dieses Commands.
     *
     * Arguments:
     * - file: Prompt-Datei relativ zu src/Service/Prompts (z.B. KiChatBotPrompt.config)
     *
     * Options:
     * - --part: system|user|both (Default: both)
     * - --var: Template-Variable(n) im Format key=value (mehrfach möglich)
     * - --no-render: Wenn gesetzt, wird nicht gerendert (rohe Templates werden ausgegeben)
     */
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

    /**
     * Führt den Dump aus: Template laden, optional Variablen rendern, Teile ausgeben.
     *
     * Validierung:
     * - --part muss system|user|both sein, sonst Command::FAILURE
     *
     * Erwartete Rückgabe von PromptTemplateLoader::load():
     * - Array mit keys "system" und/oder "user" (Strings); unbekannte Keys sind möglich, werden hier ignoriert.
     *
     * @param InputInterface  $input  CLI Input (Argumente/Optionen)
     * @param OutputInterface $output CLI Output
     *
     * @return int Symfony Command Exit Code
     *
     * @psalm-return Command::SUCCESS|Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        $part = strtolower((string) $input->getOption('part'));
        $noRender = (bool) $input->getOption('no-render');

        if (!in_array($part, ['system', 'user', 'both'], true)) {
            $output->writeln('<error>Ungültiger Wert für --part. Erlaubt: system|user|both</error>');
            return Command::FAILURE;
        }

        /**
         * Geladenes Template.
         *
         * @var array{system?: string, user?: string} $tpl
         */
        $tpl = $this->promptLoader->load($file);

        $vars = $this->parseVars((array) $input->getOption('var'));

        $system = $tpl['system'] ?? '';
        $user   = $tpl['user'] ?? '';

        // Rendering nur, wenn gewünscht und Variablen vorhanden.
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
     * Parst Template-Variablen aus CLI-Paaren im Format "key=value".
     *
     * Eigenschaften:
     * - Ignoriert Einträge ohne '=' still (damit CLI nicht nervt)
     * - Keys werden getrimmt, Values bleiben wie übergeben (inkl. Leerzeichen möglich)
     * - Leere Keys werden verworfen
     *
     * Beispiel:
     * - ["lang=de", "shop=schop-lieblingsplatz.de"] => ["lang" => "de", "shop" => "schop-lieblingsplatz.de"]
     *
     * @param string[] $pairs Liste von "key=value"-Strings (mehrfach möglich über --var)
     *
     * @return array<string, string> Map aus Variablenname => Wert
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
