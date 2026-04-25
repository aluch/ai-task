<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\AI\ConfirmationExecutor;
use App\AI\PendingActionStore;
use App\Service\TelegramUserResolver;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Обрабатывает callback'и confirm:<id>:yes и confirm:<id>:no.
 * На «yes» — consume pending action и исполнить через ConfirmationExecutor.
 * На «no» — просто удалить pending. В обоих случаях — отредактировать
 * сообщение, убрав кнопки.
 */
class ConfirmationCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly PendingActionStore $store,
        private readonly ConfirmationExecutor $executor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, string $data): void
    {
        $user = $this->userResolver->resolve($bot);
        // data ожидается как «<id>:yes» или «<id>:no»
        $parts = explode(':', $data);
        if (count($parts) !== 2) {
            $this->logger->warning('Confirmation callback: malformed data', ['data' => $data]);
            $bot->answerCallbackQuery(text: 'Битый callback', show_alert: false);

            return;
        }
        [$confirmationId, $decision] = $parts;

        if ($decision === 'no') {
            $this->store->consume($confirmationId);
            $this->editAndDropButtons($bot, '👌 Отменено, ничего не сделал.');
            $bot->answerCallbackQuery();

            return;
        }

        if ($decision !== 'yes') {
            $bot->answerCallbackQuery(text: 'Неизвестный выбор');

            return;
        }

        $action = $this->store->consume($confirmationId);
        if ($action === null) {
            $this->editAndDropButtons($bot, '⏰ Действие устарело — попроси заново.');
            $bot->answerCallbackQuery();

            return;
        }

        try {
            $resultText = $this->executor->execute($user, $action);
        } catch (\Throwable $e) {
            $this->logger->error('Confirmation executor failed', [
                'action_type' => $action->actionType,
                'error' => $e->getMessage(),
            ]);
            $resultText = '⚠️ Не получилось выполнить: ' . $e->getMessage();
        }

        $this->editAndDropButtons($bot, $resultText);
        $bot->answerCallbackQuery();
    }

    private function editAndDropButtons(Nutgram $bot, string $text): void
    {
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($chatId === null || $messageId === null) {
            return;
        }
        $bot->editMessageText(
            text: $text,
            chat_id: $chatId,
            message_id: $messageId,
            parse_mode: ParseMode::HTML,
            reply_markup: null,
        );
    }
}
