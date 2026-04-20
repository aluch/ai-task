<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Ловит все отправки Telegram-сообщений в массив — используется smoke-
 * командами чтобы не дергать реальный Telegram Bot API. Прода НЕ касается.
 */
final class InMemoryTelegramNotifier implements TelegramNotifierInterface
{
    /** @var list<array{chat_id: int|string, text: string, reply_markup: ?array, parse_mode: ?string, message_id: int}> */
    private array $messages = [];
    private int $nextMessageId = 1000;

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
    ): bool {
        $this->messages[] = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
            'parse_mode' => $parseMode,
            'message_id' => $this->nextMessageId++,
        ];

        return true;
    }

    /** @return list<array{chat_id: int|string, text: string, reply_markup: ?array, parse_mode: ?string, message_id: int}> */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function count(): int
    {
        return count($this->messages);
    }
}
