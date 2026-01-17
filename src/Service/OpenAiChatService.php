<?php

declare(strict_types=1);

namespace App\Service;

use OpenAI\Client;

final readonly class OpenAiChatService
{
    public function __construct(
        private Client $client,
        private string $apiKey,              // ✅ injected
        private string $defaultModel = 'gpt-4o-mini',
    ) {}

    /**
     * @param array<int,array{role:string,content:string}> $history
     */
    public function chat(array $history, string $kbContext = '', ?string $model = null): string
    {
        if (trim($this->apiKey) === '') {
            return '[OpenAI deaktiviert: OPENAI_API_KEY fehlt]';
        }

        $messages = [];
        foreach ($history as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');
            if ($content === '') {
                continue;
            }
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        if ($kbContext !== '') {
            $messages[] = ['role' => 'system', 'content' => $kbContext];
        }

        $resp = $this->client->chat()->create([
            'model' => $model ?: $this->defaultModel,
            'messages' => $messages,
        ]);

        $content = $resp->choices[0]->message->content ?? '';
        $content = trim((string) $content);

        return $content !== '' ? $content : '[leere Antwort]';
    }
}
