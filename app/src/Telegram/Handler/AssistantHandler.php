<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\AI\Assistant;
use App\AI\ConfirmationExecutor;
use App\AI\ConversationHistoryStore;
use App\AI\DTO\HistoryMessage;
use App\AI\PendingActionStore;
use App\Service\MarkdownToTelegramHtml;
use App\Service\TelegramUserResolver;
use App\Telegram\SearchDispatcher;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AssistantHandler
{
    /**
     * Тексты-«да» и «нет» для текстового подтверждения pending action.
     * Сравнение case-insensitive по trim'нутому тексту.
     */
    private const YES_WORDS = ['да', 'ок', 'окей', 'подтверждаю', 'давай', 'yes', '+', 'ага', 'угу', 'согласен'];
    private const NO_WORDS = ['нет', 'отмена', 'отменить', 'no', '-', 'неа', 'не надо', 'не'];

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly Assistant $assistant,
        private readonly ConversationHistoryStore $history,
        private readonly PendingActionStore $pendingStore,
        private readonly ConfirmationExecutor $executor,
        private readonly MarkdownToTelegramHtml $markdown,
        private readonly SearchDispatcher $searchDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $message = $bot->message();
        $text = trim($message?->text ?? '');

        if ($text === '') {
            return;
        }

        // Если пользователь в режиме поиска по меню (кнопка 🔍 Поиск).
        if ($this->searchDispatcher->dispatchIfWaiting($bot, $user, $text)) {
            return;
        }

        // Текстовое подтверждение pending action: если у пользователя есть
        // свежий pending и текст похож на «да/нет» — обрабатываем как кнопку.
        $textLc = mb_strtolower($text);
        $pendingId = $this->pendingStore->latestForUser($user);
        if ($pendingId !== null) {
            if (in_array($textLc, self::YES_WORDS, true)) {
                $this->handleTextConfirm($bot, $user, $pendingId, true);

                return;
            }
            if (in_array($textLc, self::NO_WORDS, true)) {
                $this->handleTextConfirm($bot, $user, $pendingId, false);

                return;
            }
            // Иначе — обычная команда; pending остаётся в Redis до TTL или
            // пока пользователь не нажмёт кнопку.
        }

        $userMsgId = $message?->message_id ?? 0;

        $replyToMsgId = null;
        $replyTo = $message?->reply_to_message ?? null;
        if ($replyTo !== null && ($replyTo->from->is_bot ?? false) === true) {
            $replyToMsgId = $replyTo->message_id;
        }

        $thinking = $bot->sendMessage(text: '🤔 Думаю...');
        $chatId = $bot->chatId();
        $messageId = $thinking?->message_id;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $result = $this->assistant->handle($user, $text, $now, $replyToMsgId);

            $this->logger->info('Assistant result', [
                'user_id' => $user->getId()->toRfc4122(),
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'iterations' => $result->iterations,
                'tools_called' => $result->toolsCalled,
                'history_size' => $this->history->getSize($user),
                'reply_context' => $replyToMsgId !== null,
            ]);

            // Парсинг маркера [CONFIRM:<id>] — заменяем на inline-кнопки.
            $replyText = $result->replyText;
            $confirmId = null;
            if (preg_match('/\[CONFIRM:([a-f0-9]{8})\]/', $replyText, $m) === 1) {
                $confirmId = $m[1];
                $replyText = trim(str_replace($m[0], '', $replyText));
            }

            $html = $this->markdown->convert($replyText);
            $keyboard = $confirmId !== null ? $this->buildConfirmKeyboard($confirmId) : null;
            $this->sendOrEdit($bot, $chatId, $messageId, $html, $keyboard);

            $this->history->append($user, new HistoryMessage(
                role: 'user',
                text: $text,
                telegramMsgId: $userMsgId,
                at: $now,
                replyToMsgId: $replyToMsgId,
            ));
            $this->history->append($user, new HistoryMessage(
                role: 'assistant',
                text: $result->replyText,
                telegramMsgId: $messageId ?? 0,
                at: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                toolsCalled: $result->toolsCalled,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Assistant failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $this->sendOrEdit($bot, $chatId, $messageId, '⚠️ Что-то пошло не так, попробуй ещё раз.', null);
        }
    }

    private function handleTextConfirm(Nutgram $bot, $user, string $pendingId, bool $yes): void
    {
        if (!$yes) {
            $this->pendingStore->consume($pendingId);
            $bot->sendMessage(text: '👌 Отменено, ничего не сделал.');

            return;
        }
        $action = $this->pendingStore->consume($pendingId);
        if ($action === null) {
            $bot->sendMessage(text: '⏰ Действие устарело — попроси заново.');

            return;
        }
        try {
            $result = $this->executor->execute($user, $action);
        } catch (\Throwable $e) {
            $this->logger->error('Text-confirm executor failed', [
                'action_type' => $action->actionType,
                'error' => $e->getMessage(),
            ]);
            $result = '⚠️ Не получилось выполнить: ' . $e->getMessage();
        }
        $bot->sendMessage(text: $result);
    }

    private function buildConfirmKeyboard(string $confirmId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(text: '✅ Подтверждаю', callback_data: "confirm:{$confirmId}:yes"),
            InlineKeyboardButton::make(text: '❌ Отмена', callback_data: "confirm:{$confirmId}:no"),
        );
    }

    private function sendOrEdit(
        Nutgram $bot,
        int $chatId,
        ?int $messageId,
        string $html,
        ?InlineKeyboardMarkup $keyboard,
    ): void {
        if ($messageId !== null) {
            $bot->editMessageText(
                text: $html,
                chat_id: $chatId,
                message_id: $messageId,
                parse_mode: ParseMode::HTML,
                reply_markup: $keyboard,
            );
        } else {
            $bot->sendMessage(
                text: $html,
                parse_mode: ParseMode::HTML,
                reply_markup: $keyboard,
            );
        }
    }
}
