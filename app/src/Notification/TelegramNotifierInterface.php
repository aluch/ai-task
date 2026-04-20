<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Всё что код должен знать об отправке в Telegram side-channel
 * (вне polling-цикла бота). Прод — `TelegramNotifier` (HTTP к Bot API),
 * smoke-тесты — `InMemoryTelegramNotifier`.
 *
 * Handlers ВНУТРИ polling-цикла по-прежнему используют Nutgram напрямую.
 */
interface TelegramNotifierInterface
{
    /**
     * @param array<int, array<int, array{text: string, callback_data?: string}>>|null $replyMarkup
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
    ): bool;
}
