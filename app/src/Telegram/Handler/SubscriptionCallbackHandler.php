<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Subscription\SubscriptionStatus;
use App\Service\Subscription\SubscriptionService;
use App\Service\TelegramUserResolver;
use App\Telegram\UI\SubscriptionMessageBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Callback'и subscription:* — S5 версия.
 *
 *   subscription:disable_rebill          — пользователь нажал «❌ Отключить
 *                                          автопродление», показываем confirm.
 *   subscription:disable_rebill:confirm  — auto_rebill_enabled=false. Подписка
 *                                          остаётся active до currentPeriodEnd.
 *   subscription:disable_rebill:abort    — возврат к экрану /subscription.
 *   subscription:enable_rebill           — обратное включение auto-rebill
 *                                          (без confirm — действие безвредное).
 *
 * Старый hard-cancel из S3 (subscription:cancel:*) удалён: он делал
 * status=Cancelled, что блокировало текущий период до конца. Disable_rebill
 * даёт то же поведение «доступ до конца, дальше Free» более понятно для
 * пользователя.
 */
class SubscriptionCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionHandler $subscriptionHandler,
        private readonly SubscriptionMessageBuilder $builder,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, string $data): void
    {
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $sub = $parts[1] ?? null;

        if ($action === 'disable_rebill' && $sub === null) {
            $this->showDisableConfirm($bot);

            return;
        }
        if ($action === 'disable_rebill' && $sub === 'confirm') {
            $this->doDisableRebill($bot);

            return;
        }
        if ($action === 'disable_rebill' && $sub === 'abort') {
            $this->abortDisable($bot);

            return;
        }
        if ($action === 'enable_rebill' && $sub === null) {
            $this->doEnableRebill($bot);

            return;
        }

        $bot->answerCallbackQuery(text: 'Неизвестное действие');
    }

    private function showDisableConfirm(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $sub = $this->subscriptions->getActiveSubscription($user);

        if ($sub === null || $sub->getStatus() !== SubscriptionStatus::Active) {
            $bot->answerCallbackQuery(text: 'Активной Pro-подписки нет.');

            return;
        }
        if (!$sub->isAutoRebillEnabled()) {
            $bot->answerCallbackQuery(text: 'Автопродление уже отключено.');

            return;
        }

        $bot->answerCallbackQuery();

        $payload = $this->builder->buildDisableRebillConfirm($user, $sub);
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

    private function doDisableRebill(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $sub = $this->subscriptions->getActiveSubscription($user);

        if ($sub === null || $sub->getStatus() !== SubscriptionStatus::Active) {
            $bot->answerCallbackQuery(text: 'Активной Pro-подписки нет.');

            return;
        }

        $sub->setAutoRebillEnabled(false);
        $this->doctrine->getManager()->flush();

        $this->logger->info('Auto-rebill disabled by user', [
            'user_id' => $user->getId()->toRfc4122(),
            'subscription_id' => $sub->getId()->toRfc4122(),
        ]);

        $bot->answerCallbackQuery();
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        $text = $this->builder->buildDisableRebillDone($user, $sub);
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

    private function doEnableRebill(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $sub = $this->subscriptions->getActiveSubscription($user);

        if ($sub === null || $sub->getStatus() !== SubscriptionStatus::Active) {
            $bot->answerCallbackQuery(text: 'Активной Pro-подписки нет.');

            return;
        }
        if ($sub->isAutoRebillEnabled()) {
            $bot->answerCallbackQuery(text: 'Автопродление уже включено.');

            return;
        }

        $sub->setAutoRebillEnabled(true);
        $this->doctrine->getManager()->flush();

        $this->logger->info('Auto-rebill enabled by user', [
            'user_id' => $user->getId()->toRfc4122(),
            'subscription_id' => $sub->getId()->toRfc4122(),
        ]);

        $bot->answerCallbackQuery();
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        $text = $this->builder->buildEnableRebillDone($user, $sub);
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

    private function abortDisable(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();
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
