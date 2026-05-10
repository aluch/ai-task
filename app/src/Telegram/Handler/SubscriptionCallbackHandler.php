<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Subscription\SubscriptionStatus;
use App\Service\Subscription\SubscriptionService;
use App\Service\TelegramUserResolver;
use App\Telegram\UI\SubscriptionMessageBuilder;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Callback'и subscription:* — двухшаговый flow отмены:
 *   subscription:cancel          — пользователь нажал «❌ Отменить подписку»,
 *                                  показываем confirm-экран.
 *   subscription:cancel:confirm  — подтверждение, вызываем cancel().
 *   subscription:cancel:abort    — возвращаемся к экрану /subscription.
 */
class SubscriptionCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionHandler $subscriptionHandler,
        private readonly SubscriptionMessageBuilder $builder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, string $data): void
    {
        // data ожидается: "cancel" | "cancel:confirm" | "cancel:abort"
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $sub = $parts[1] ?? null;

        if ($action === 'cancel' && $sub === null) {
            $this->showConfirm($bot);

            return;
        }
        if ($action === 'cancel' && $sub === 'confirm') {
            $this->doCancel($bot);

            return;
        }
        if ($action === 'cancel' && $sub === 'abort') {
            $this->abortCancel($bot);

            return;
        }

        $bot->answerCallbackQuery(text: 'Неизвестное действие');
    }

    private function showConfirm(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $sub = $this->subscriptions->getActiveSubscription($user);

        if ($sub === null || $sub->getStatus() !== SubscriptionStatus::Active) {
            $bot->answerCallbackQuery(text: 'Активной Pro-подписки нет.');

            return;
        }

        $bot->answerCallbackQuery();

        $payload = $this->builder->buildCancelConfirm($user, $sub);
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($chatId !== null && $messageId !== null) {
            $bot->editMessageText(
                text: $payload['text'],
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: $this->arrayKeyboardToNutgram($payload['keyboard']),
            );
        }
    }

    private function doCancel(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sub = $this->subscriptions->getActiveSubscription($user);

        if ($sub === null || $sub->getStatus() !== SubscriptionStatus::Active) {
            $bot->answerCallbackQuery(text: 'Активной Pro-подписки нет.');

            return;
        }

        $this->subscriptions->cancel($sub, $now);
        $this->logger->info('Subscription cancelled by user', [
            'user_id' => $user->getId()->toRfc4122(),
        ]);

        $bot->answerCallbackQuery();
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        $text = $this->builder->buildCancelDone($user, $sub);
        if ($chatId !== null && $messageId !== null) {
            $bot->editMessageText(
                text: $text,
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: null,
            );
        } else {
            $bot->sendMessage(text: $text);
        }
    }

    private function abortCancel(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();
        // Возвращаемся к экрану /subscription. Просто шлём свежий
        // экран новым сообщением — попытка отредактировать предыдущее
        // не дала бы клавиатуры с двухкнопочным confirm-rerwriting.
        ($this->subscriptionHandler)($bot);
    }

    /**
     * @param array<int, array<int, array{text: string, callback_data: string}>> $rows
     */
    private function arrayKeyboardToNutgram(array $rows): InlineKeyboardMarkup
    {
        $kb = InlineKeyboardMarkup::make();
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $btn) {
                $buttons[] = InlineKeyboardButton::make(
                    text: $btn['text'],
                    callback_data: $btn['callback_data'],
                );
            }
            $kb->addRow(...$buttons);
        }

        return $kb;
    }
}
