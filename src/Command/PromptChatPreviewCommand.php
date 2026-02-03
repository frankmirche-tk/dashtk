<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SupportChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:chat:prompt-preview',
    description: 'Simuliert den finalen KI-Prompt (History + KB-Context + User) OHNE einen Request an die KI zu senden.'
)]
final class PromptChatPreviewCommand extends Command
{
    public function __construct(
        private readonly SupportChatService $supportChatService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sessionId', InputArgument::REQUIRED, 'Session-ID (wie im Frontend verwendet)')
            ->addArgument('message', InputArgument::REQUIRED, 'User Message, z.B. "Reduzierungen Newsletter seit 01.01.2025"')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider (openai|gemini|auto)', 'gemini')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model (optional, sonst Default aus ENV)', '')
            ->addOption('usageKey', null, InputOption::VALUE_REQUIRED, 'usage_key (optional)', '')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Wenn gesetzt: führt den Request wirklich aus (executePrompt statt previewPrompt)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Wenn gesetzt: Ausgabe als JSON (maschinenlesbar)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = (string)$input->getArgument('sessionId');
        $message   = (string)$input->getArgument('message');

        $provider  = strtolower(trim((string)$input->getOption('provider')));
        $modelOpt  = trim((string)$input->getOption('model'));
        $usageKey  = trim((string)$input->getOption('usageKey'));
        $doExecute = (bool)$input->getOption('execute');

        $ctx = $usageKey !== '' ? ['usage_key' => $usageKey] : [];

        if ($doExecute) {
            $preview = $this->supportChatService->executePrompt(
                sessionId: $sessionId,
                message: $message,
                provider: $provider !== '' ? $provider : 'auto',
                model: $modelOpt !== '' ? $modelOpt : null,
                context: $ctx
            );
        } else {
            $preview = $this->supportChatService->previewPrompt(
                sessionId: $sessionId,
                message: $message,
                provider: $provider !== '' ? $provider : 'gemini',
                model: $modelOpt !== '' ? $modelOpt : null,
                context: $ctx
            );
        }


        if ((bool)$input->getOption('json')) {
            $output->writeln(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $output->writeln('===== PROMPT PREVIEW =====');
        $output->writeln('Provider: ' . ($preview['provider'] ?? '<n/a>'));
        if (isset($preview['provider_used'])) {
            $output->writeln('Provider used: ' . (string)$preview['provider_used']);
        }
        $output->writeln('Model: ' . ($preview['model'] ?? '<n/a>'));
        $output->writeln('History messages: ' . (string)($preview['history_count'] ?? 0));
        $output->writeln('KB context chars: ' . (string)($preview['kb_context_chars'] ?? 0));
        $output->writeln('');

        $output->writeln('----- KB_CONTEXT -----');
        $output->writeln((string)($preview['kbContext'] ?? ''));
        $output->writeln('');

        $output->writeln('----- HISTORY (final, trimmed) -----');
        foreach (($preview['history'] ?? []) as $i => $msg) {
            $role = (string)($msg['role'] ?? '');
            $content = (string)($msg['content'] ?? '');
            $output->writeln('#' . $i . ' [' . $role . ']');
            $output->writeln($content);
            $output->writeln('');
        }

        if ($doExecute) {
            $output->writeln('----- ANSWER -----');
            $output->writeln((string)($preview['answer'] ?? ''));
            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
