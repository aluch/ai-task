<?php

declare(strict_types=1);

namespace App\AI\DTO;

/**
 * Одно сообщение в истории диалога Ассистента с пользователем.
 * Хранится в Redis (ConversationHistoryStore) для контекстной памяти
 * между сообщениями. Не содержит tool_use/tool_result — только финальные
 * тексты реплик (чтобы уменьшить размер и не запутать модель).
 */
final readonly class HistoryMessage
{
    /**
     * @param string $role 'user' | 'assistant'
     * @param list<string> $toolsCalled список имён вызванных tools (только для assistant)
     */
    public function __construct(
        public string $role,
        public string $text,
        public int $telegramMsgId,
        public \DateTimeImmutable $at,
        public ?int $replyToMsgId = null,
        public array $toolsCalled = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'text' => $this->text,
            'telegram_msg_id' => $this->telegramMsgId,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'reply_to_msg_id' => $this->replyToMsgId,
            'tools_called' => $this->toolsCalled,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            role: (string) ($data['role'] ?? 'user'),
            text: (string) ($data['text'] ?? ''),
            telegramMsgId: (int) ($data['telegram_msg_id'] ?? 0),
            at: new \DateTimeImmutable((string) ($data['at'] ?? 'now'), new \DateTimeZone('UTC')),
            replyToMsgId: isset($data['reply_to_msg_id']) ? (int) $data['reply_to_msg_id'] : null,
            toolsCalled: array_values(array_filter(
                (array) ($data['tools_called'] ?? []),
                'is_string',
            )),
        );
    }
}
