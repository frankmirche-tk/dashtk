<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SupportChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TestGeminiCommand
 *
 * Purpose:
 * - Quick health check for the Gemini provider configuration (API key / model / connectivity).
 * - Sends a minimal "Hello AI" request through the real production path:
 *   SupportChatService -> AiChatGateway -> ChatAdapterRegistry -> Gemini adapter.
 *
 * Why it exists:
 * - Avoids ambiguous low-level SDK errors by testing the exact same code path used by the API.
 * - Helps to distinguish configuration/provider problems from application logic problems.
 *
 * What this command tests:
 * - The application can create a Gemini adapter and perform a chat request.
 * - The configured Gemini key is accepted by the provider (no auth error).
 * - A non-empty assistant response can be received.
 *
 * What this command does NOT test:
 * - SOP/KB matching quality (it intentionally uses a simple static message).
 * - Conversation history behavior beyond a single request.
 * - Advanced provider features (tools, streaming, safety settings, etc.).
 *
 * Environment variables:
 * - Optional:
 *   - GEMINI_DEFAULT_MODEL: if set, used as explicit model override for this test.
 *     If not set (or empty), the gateway/provider default model configuration is used.
 *
 * Usage:
 * - php bin/console app:test-gemini
 *
 * Exit codes:
 * - SUCCESS: a non-empty response was received.
 * - FAILURE: provider call failed, or response was empty.
 */
#[AsCommand(
    name: 'app:test-gemini',
    description: 'Testet den Gemini Key über SupportChatService/AiChatGateway mit "Hello AI"'
)]
final class TestGeminiCommand extends Command
{
    /**
     * @param SupportChatService $chatService Service used to execute the request through the production pipeline.
     */
    public function __construct(
        private readonly SupportChatService $chatService,
    ) {
        parent::__construct();
    }

    /**
     * Executes a minimal provider test request ("Hello AI") using the Gemini provider.
     *
     * Implementation details:
     * - Creates a random sessionId to keep the call structure identical to the API.
     * - Uses provider "gemini" explicitly.
     * - Optionally applies GEMINI_DEFAULT_MODEL as a model override if present.
     *
     * @param InputInterface  $input  CLI input (unused).
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS on a successful provider response, otherwise Command::FAILURE.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Optional: use explicit model override for the test run.
        // If not set, SupportChatService/AiChatGateway will use the configured provider default model.
        $model = isset($_ENV['GEMINI_DEFAULT_MODEL']) && is_string($_ENV['GEMINI_DEFAULT_MODEL'])
            ? trim($_ENV['GEMINI_DEFAULT_MODEL'])
            : null;

        // Random session ID so caching/history keys don't collide with other runs.
        $sessionId = bin2hex(random_bytes(16));

        try {
            $result = $this->chatService->ask(
                sessionId: $sessionId,
                message: 'Hello AI',
                dbOnlySolutionId: null,
                provider: 'gemini',
                model: $model !== '' ? $model : null,
                context: []
            );

            $answer = trim((string) ($result['answer'] ?? ''));

            if ($answer === '') {
                $io->warning('Gemini hat geantwortet, aber ohne Text.');
                return Command::FAILURE;
            }

            $io->success('Gemini Key funktioniert ✅');
            $io->text($answer);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Gemini Test fehlgeschlagen ❌');
            $io->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }
}
