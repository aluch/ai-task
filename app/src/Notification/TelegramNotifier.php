<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Тонкая HTTP-обёртка над Telegram Bot API для отправки сообщений ИЗВНЕ
 * polling-цикла — из Scheduler'а, Messenger worker'ов и т.п. Поднимать
 * Nutgram ради одного sendMessage избыточно; прямой POST проще и не
 * требует перехватывать update'ы.
 *
 * Для сообщений ВНУТРИ polling-цикла (handlers бота) используй Nutgram.
 */
class TelegramNotifier
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $token,
    ) {
    }

    /**
     * Отправляет сообщение с опциональной inline-клавиатурой и parse_mode.
     *
     * @param array<int, array<int, array{text: string, callback_data?: string}>>|null $replyMarkup
     *   массив рядов inline-кнопок (структура Telegram API); null = без клавиатуры
     *
     * @return bool true при успехе; false при ошибке (логируется). Ошибки не
     *   пробрасываются, чтобы один несработавший notify не убивал worker.
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
    ): bool {
        if ($this->token === '') {
            $this->logger->error('TelegramNotifier: token not configured');

            return false;
        }

        $url = 'https://api.telegram.org/bot' . $this->token . '/sendMessage';

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode(
                ['inline_keyboard' => $replyMarkup],
                \JSON_UNESCAPED_UNICODE,
            );
        }
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'timeout' => 10,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $body = $response->toArray(false);
            $this->logger->warning('TelegramNotifier: non-2xx response', [
                'chat_id' => $chatId,
                'status' => $status,
                'description' => $body['description'] ?? null,
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('TelegramNotifier: request failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
