<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SupportChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-gemini',
    description: 'Interaktiver Drucker-Chatbot für DashTK (nutzt SupportChatService wie die API)'
)]
final class AppTestGeminiCommand extends Command
{
    public function __construct(
        private readonly SupportChatService $chatService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('DashTK Drucker-Experte');
        $io->info('Tippe "exit" zum Beenden.');

        // Session-ID für die komplette CLI-Session (damit Conversation-Memory funktioniert)
        $sessionId = bin2hex(random_bytes(16));
        $io->comment('SessionId: ' . $sessionId);

        while (true) {
            $userQuestion = $io->ask('Wie kann ich dir mit deinem Drucker helfen?');

            if ($userQuestion === null || strtolower(trim($userQuestion)) === 'exit') {
                $io->note('Chat beendet.');
                break;
            }

            $userQuestion = trim($userQuestion);
            if ($userQuestion === '') {
                continue;
            }

            try {
                $result = $this->chatService->ask($sessionId, $userQuestion);

                // Optional: KB Treffer sichtbar machen (für Debug)
                if (!empty($result['kbMatches'])) {
                    $io->text('KB-Treffer:');
                    foreach ($result['kbMatches'] as $m) {
                        $io->writeln(sprintf(' - Score %d: %s', (int)$m['score'], (string)$m['title']));
                    }
                    $io->newLine();
                } else {
                    $io->text('KB-Treffer: (keine)');
                    $io->newLine();
                }

                $io->section('Gemini Antwort:');
                $io->writeln((string) ($result['answer'] ?? '[leer]'));
                $io->newLine();
            } catch (\Throwable $e) {
                $io->error('Fehler: ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
