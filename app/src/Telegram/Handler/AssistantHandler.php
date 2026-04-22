<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\AI\Assistant;
use App\AI\ConversationHistoryStore;
use App\AI\DTO\HistoryMessage;
use App\Service\MarkdownToTelegramHtml;
use App\Service\TelegramUserResolver;
use App\Telegram\SearchDispatcher;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class AssistantHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly Assistant $assistant,
        private readonly ConversationHistoryStore $history,
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

        // Если пользователь в режиме поиска по меню (кнопка 🔍 Поиск),
        // текущий текст — это поисковый запрос, а не обычное сообщение.
        if ($this->searchDispatcher->dispatchIfWaiting($bot, $user, $text)) {
            return;
        }

        $userMsgId = $message?->message_id ?? 0;

        // Reply-detection: если пользователь ответил на сообщение нашего
        // бота — передаём reply_to_msg_id в Ассистент, он подтянет текст
        // из истории и добавит <reply_context>. Если сообщения уже нет в
        // истории (TTL вышел или выпало из окна) — просто игнорируем.
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

            $html = $this->markdown->convert($result->replyText);
            $this->editOrSend($bot, $chatId, $messageId, $html);

            // Сохраняем оба сообщения в историю только после успешного
            // прохождения Ассистента — иначе при сбое Claude остался бы
            // user-message без пары.
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
                // editMessageText редактирует $thinking, не создаёт новое —
                // msg_id остаётся тот же, это и есть id бот-ответа.
                telegramMsgId: $messageId ?? 0,
                at: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                toolsCalled: $result->toolsCalled,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Assistant failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $this->editOrSend(
                $bot,
                $chatId,
                $messageId,
                '⚠️ Что-то пошло не так, попробуй ещё раз.',
            );
        }
    }

    private function editOrSend(Nutgram $bot, int $chatId, ?int $messageId, string $html): void
    {
        if ($messageId !== null) {
            $bot->editMessageText(
                text: $html,
                chat_id: $chatId,
                message_id: $messageId,
                parse_mode: ParseMode::HTML,
            );
        } else {
            $bot->sendMessage(text: $html, parse_mode: ParseMode::HTML);
        }
    }
}
