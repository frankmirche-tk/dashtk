<?php

declare(strict_types=1);

namespace App\AI;

use ModelflowAi\Chat\Request\AIChatMessageCollection;
use ModelflowAi\Chat\Request\AIChatRequest;

final readonly class AiChatGateway
{
    public function __construct(
        private ChatAdapterRegistry $registry,
        private string $defaultProvider,
    ) {}

    /**
     * @param array<int,array{role:string,content:string}> $history
     */
    public function chat(
        array $history,
        string $kbContext = '',
        ?string $provider = null,
        ?string $model = null,
        array $context = []
    ): string {
        $provider = $provider ? strtolower(trim($provider)) : $this->defaultProvider;

        if ($kbContext !== '') {
            $history[] = ['role' => 'system', 'content' => $kbContext];
        }

        $adapter = $this->registry->create($provider, [
            'model' => $model,
            'context' => $context,
        ]);

        $messages = $this->buildMessageCollection($history);

        $request = $this->makeAiChatRequest($messages);

        $response = $adapter->handleRequest($request);

        return $this->extractResponseText($response->getMessage());
    }

    /**
     * Baut eine AIChatMessageCollection kompatibel zu deiner Modelflow-Version:
     * - Die Collection erwartet AIChatMessage-Objekte, nicht Arrays.
     */
    private function buildMessageCollection(array $history): AIChatMessageCollection
    {
        $messages = new AIChatMessageCollection();

        foreach ($history as $m) {
            $role = strtolower(trim((string)($m['role'] ?? 'user')));
            $content = trim((string)($m['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $messages->append($this->makeAiChatMessage($role, $content));
        }

        return $messages;
    }

    private function makeAiChatMessage(string $role, string $content): object
    {
        // Diese Klassen existieren in deiner Modelflow-Version (siehe Fehlerlog).
        $msgClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessage';
        $roleEnumClass = 'ModelflowAi\\Chat\\Request\\Message\\AIChatMessageRoleEnum';

        if (!class_exists($msgClass) || !class_exists($roleEnumClass)) {
            // Fallback (sollte bei dir nicht passieren): array-basierte Message
            return [
                'role' => $role,
                'content' => $content,
            ];
        }

        /** @var class-string $roleEnumClass */
        $roleEnum = $this->mapRoleEnum($roleEnumClass, $role);

        // ctor: (roleEnum, content)
        return new $msgClass($roleEnum, $content);
    }

    private function mapRoleEnum(string $roleEnumClass, string $role): object
    {
        $role = strtolower($role);

        // sichere Werte
        $wanted = match ($role) {
            'system' => 'SYSTEM',
            'assistant' => 'ASSISTANT',
            default => 'USER',
        };

        // PHP 8.1+ backed enums: ::SYSTEM, ::USER, ::ASSISTANT
        if (defined($roleEnumClass . '::' . $wanted)) {
            return constant($roleEnumClass . '::' . $wanted);
        }

        // Fallback auf USER
        if (defined($roleEnumClass . '::USER')) {
            return constant($roleEnumClass . '::USER');
        }

        // Notfall: null vermeiden (sonst genau dein Fehler)
        throw new \RuntimeException('AIChatMessageRoleEnum not resolvable');
    }

    private function extractResponseText(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        // Bei dir ist es AIChatResponseMessage (Objekt)
        if (is_object($message)) {
            if (method_exists($message, 'getContent')) {
                return trim((string)$message->getContent());
            }
            if (property_exists($message, 'content')) {
                return trim((string)$message->content);
            }
            if (method_exists($message, '__toString')) {
                return trim((string)$message);
            }
        }

        return '[unlesbare Antwort]';
    }

    private function makeAiChatRequest(AIChatMessageCollection $messages): AIChatRequest
    {
        // Deine Reflection-Ausgabe zeigt: ctor erwartet mindestens 6 Parameter.
        // Wir erzeugen die notwendigen Objekte sauber und kompatibel.

        $criteriaClass = 'ModelflowAi\\DecisionTree\\Criteria\\CriteriaCollection';
        if (!class_exists($criteriaClass)) {
            throw new \RuntimeException('CriteriaCollection class not found: ' . $criteriaClass);
        }

        $criteria = new $criteriaClass();

        // tools, toolInfos, options: leere arrays ok
        $tools = [];
        $toolInfos = [];
        $options = [];

        // requestHandler muss callable sein – minimaler no-op handler
        $requestHandler = static function (): void {};

        // metadata optional
        $metadata = [];

        // responseFormat optional (null)
        $responseFormat = null;

        // toolChoice Enum
        $toolChoiceEnumClass = 'ModelflowAi\\Chat\\ToolInfo\\ToolChoiceEnum';
        $toolChoice = defined($toolChoiceEnumClass . '::AUTO')
            ? constant($toolChoiceEnumClass . '::AUTO')
            : (defined($toolChoiceEnumClass . '::auto') ? constant($toolChoiceEnumClass . '::auto') : 'auto');

        // ctor Signatur (aus deiner Reflection):
        // __construct(
        //   AIChatMessageCollection $messages,
        //   CriteriaCollection $criteria,
        //   array $tools,
        //   array $toolInfos,
        //   array $options,
        //   callable $requestHandler,
        //   array $metadata = [],
        //   ?ResponseFormatInterface $responseFormat = null,
        //   ToolChoiceEnum $toolChoice = "auto"
        // )
        return new AIChatRequest(
            $messages,
            $criteria,
            $tools,
            $toolInfos,
            $options,
            $requestHandler,
            $metadata,
            $responseFormat,
            $toolChoice
        );
    }
}
