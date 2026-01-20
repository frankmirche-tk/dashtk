<?php

declare(strict_types=1);

namespace App\Command;

use OpenAI;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TestOpenAiCommand
 *
 * Purpose:
 * - Minimal health check for the OpenAI provider configuration (API key / model / connectivity).
 * - Sends a simple "Hello AI" chat request and verifies that a non-empty response is returned.
 *
 * What this command tests:
 * - The OpenAI SDK client can be created with the provided OPENAI_API_KEY.
 * - The configured model is reachable and the API returns at least one choice with message content.
 * - Basic request/response wiring is functional (auth + network + model access).
 *
 * What this command does NOT test:
 * - Your application’s SupportChatService / AiChatGateway / adapter registry integration (this command uses the SDK directly).
 * - SOP/KB matching or any business logic.
 * - Streaming, tools/function calling, or advanced response formats.
 *
 * Environment variables:
 * - Required:
 *   - OPENAI_API_KEY: API key used to authenticate against OpenAI.
 * - Optional:
 *   - OPENAI_TEST_MODEL: model name to use for the test request (default: "gpt-4o-mini").
 *
 * Usage:
 * - php bin/console app:test-openai
 *
 * Exit codes:
 * - SUCCESS: request completed and returned a response (even if empty, the command currently still returns SUCCESS).
 * - FAILURE: missing API key or an exception occurred during the API call.
 *
 * Note:
 * - If you want this command to fail on empty responses as well, change the "empty answer" branch to return FAILURE.
 */
#[AsCommand(
    name: 'app:test-openai',
    description: 'Testet den OpenAI API-Key mit einer einfachen "Hello AI"-Anfrage'
)]
final class TestOpenAiCommand extends Command
{
    /**
     * Executes a minimal OpenAI chat request ("Hello AI") and prints the response.
     *
     * @param InputInterface  $input  CLI input (unused).
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS on successful call, otherwise Command::FAILURE.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
        if (!$apiKey) {
            $io->error('OPENAI_API_KEY ist nicht gesetzt.');
            return Command::FAILURE;
        }

        $model = $_ENV['OPENAI_TEST_MODEL'] ?? 'gpt-4o-mini';

        try {
            $client = OpenAI::client($apiKey);

            $response = $client->chat()->create([
                'model' => $model,
                'messages' => [
                    // Keep the response deterministic and short for a connectivity/auth test.
                    ['role' => 'system', 'content' => 'Antworte kurz und knapp.'],
                    ['role' => 'user', 'content' => 'Hello AI'],
                ],
            ]);

            $answer = trim((string) ($response->choices[0]->message->content ?? ''));

            if ($answer === '') {
                $io->warning('OpenAI hat geantwortet, aber ohne Text.');
                // Optional hard-fail:
                // return Command::FAILURE;
            } else {
                $io->success('OpenAI API-Key funktioniert ✅');
                $io->text('Antwort:');
                $io->writeln($answer);
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('OpenAI Test fehlgeschlagen ❌');
            $io->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }
}
