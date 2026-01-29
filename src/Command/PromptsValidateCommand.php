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
    name: 'app:prompts:validate',
    description: 'Validiert Prompt-Dateien (Existenz, Parsebarkeit, Pflichtblöcke, Platzhalter-Hinweise).'
)]
final class PromptsValidateCommand extends Command
{
    public function __construct(
        private readonly PromptTemplateLoader $promptLoader,
    ) {
        parent::__construct();
    }


    private const PLACEHOLDER_WHITELIST = [
        'TKFashionPolicyPrompt.config' => [],
        'TKFashionSpezifischInventoryPrompt.config' => [],
        'TKFashionContactsPrompt.config' => [],
        'KiChatBotPrompt.config' => ['message'],
        'NewsletterCreatePrompt.config' => ['filename','year','kw','driveUrl','pdfText'],
        'FormCreatePrompt.config' => ['filename','driveUrl','pdfText'],
    ];

    protected function configure(): void
    {
        $this
            ->addArgument(
                'files',
                InputArgument::IS_ARRAY,
                'Prompt-Dateien relativ zu src/Service/Prompts (z.B. KiChatBotPrompt.config). Leer = Default-Liste.'
            )
            ->addOption(
                'fail-on-unknown-placeholders',
                null,
                InputOption::VALUE_NONE,
                'Fehlschlagen, wenn nicht erlaubte {{placeholders}} gefunden werden (Whitelist).'
            )
            ->addOption(
                'require-user',
                null,
                InputOption::VALUE_NONE,
                'Wenn gesetzt: USER-Block ist Pflicht (sonst nur SYSTEM Pflicht).'
            );
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = (array) $input->getArgument('files');
        $requireUser = (bool) $input->getOption('require-user');
        $failOnUnknown = (bool) $input->getOption('fail-on-unknown-placeholders');

        if ($files === []) {
            $files = [
                'TKFashionPolicyPrompt.config',
                'TKFashionSpezifischInventoryPrompt.config',
                'TKFashionContactsPrompt.config',
                'KiChatBotPrompt.config',
                'NewsletterCreatePrompt.config',
                'FormCreatePrompt.config',
            ];

        }

        $baseDir = $this->promptLoader->getBaseDir();
        $output->writeln('<info>Validiere Prompt-Dateien</info>');
        $output->writeln('BaseDir: ' . $baseDir);
        $output->writeln('');

        $hasErrors = false;

        foreach ($files as $file) {
            $output->writeln('• <comment>' . $file . '</comment>');

            try {
                // 1) Loader nutzen (prüft Existenz indirekt; wir geben aber bessere Meldung)
                $abs = rtrim($baseDir, '/') . '/' . ltrim((string)$file, '/');
                if (!is_file($abs)) {
                    $output->writeln('  <error>FEHLER:</error> Datei nicht gefunden: ' . $abs);
                    $hasErrors = true;
                    continue;
                }

                $tpl = $this->promptLoader->load((string)$file);

                // 2) Pflichtblock SYSTEM
                if (trim($tpl['system'] ?? '') === '') {
                    $output->writeln('  <error>FEHLER:</error> [SYSTEM] Block fehlt oder ist leer.');
                    $hasErrors = true;
                } else {
                    $output->writeln('  <info>OK</info> [SYSTEM] gefunden (' . mb_strlen($tpl['system']) . ' chars)');
                }

                // 3) Optional/Required USER
                $user = trim($tpl['user'] ?? '');
                if ($requireUser && $user === '') {
                    $output->writeln('  <error>FEHLER:</error> [USER] Block ist erforderlich, aber fehlt/leer.');
                    $hasErrors = true;
                } elseif ($user !== '') {
                    $output->writeln('  <info>OK</info> [USER] gefunden (' . mb_strlen($user) . ' chars)');
                } else {
                    $output->writeln('  <comment>Hinweis:</comment> [USER] fehlt/leer (ist hier erlaubt).');
                }

                // 4) Platzhalter-Check (nur Hinweis oder strict)
                $placeholders = $this->collectPlaceholders(
                    ($tpl['system'] ?? '') . "\n" . ($tpl['user'] ?? '')
                );

                $allowed = self::PLACEHOLDER_WHITELIST[$file] ?? null;

                if ($allowed !== null) {
                    sort($allowed);
                    sort($placeholders);

                    $unknown = array_diff($placeholders, $allowed);
                    $missing = array_diff($allowed, $placeholders);

                    if ($unknown !== []) {
                        $msg = 'Unbekannte Platzhalter: ' . implode(', ', $unknown);

                        if ($failOnUnknown) {
                            $output->writeln('  <error>FEHLER:</error> ' . $msg);
                            $hasErrors = true;
                        } else {
                            $output->writeln('  <comment>Hinweis:</comment> ' . $msg);
                        }
                    } else {
                        $output->writeln('  <info>OK</info> Keine unbekannten Platzhalter.');
                    }

                    if ($missing !== []) {
                        $output->writeln(
                            '  <comment>Hinweis:</comment> Erwartete Platzhalter fehlen: ' .
                            implode(', ', $missing)
                        );
                    }
                } else {
                    // keine Whitelist definiert → nur Info
                    if ($placeholders !== []) {
                        $output->writeln(
                            '  <comment>Hinweis:</comment> Platzhalter gefunden: ' .
                            implode(', ', $placeholders)
                        );
                    }
                }

            } catch (\Throwable $e) {
                $output->writeln('  <error>FEHLER:</error> ' . $e->getMessage());
                $hasErrors = true;
            }

            $output->writeln('');
        }

        if ($hasErrors) {
            $output->writeln('<error>Validierung fehlgeschlagen.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Alle Prompts sind valide.</info>');
        return Command::SUCCESS;
    }

    /**
     * @return string[] unique placeholder names, e.g. ["filename","kw"]
     */
    private function collectPlaceholders(string $text): array
    {
        if (!preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $text, $m)) {
            return [];
        }
        $names = array_values(array_unique($m[1]));
        sort($names);
        return $names;
    }
}
