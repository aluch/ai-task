<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Subscription\SubscriptionStatus;
use App\Service\AccessGate;
use App\Service\Subscription\SubscriptionService;
use App\Service\TelegramUserResolver;
use App\Telegram\UI\UpgradeMessageBuilder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * /upgrade — экран для оформления Pro-подписки. Внутри только
 * роутинг по статусу пользователя; контент готовит UpgradeMessageBuilder.
 *
 * - admin → пояснение что админ безлимитен и есть админские grant'ы;
 * - active Pro → «у тебя уже Pro», ссылка на /subscription;
 * - trialing → trial-mid CTA «оформи сейчас», кнопки оплаты;
 * - free / нет подписки → полный pitch с кнопками оплаты.
 *
 * Кнопка «Оплатить» в S3 — заглушка (см. UpgradePayCallbackHandler).
 */
class UpgradeHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly SubscriptionService $subscriptions,
        private readonly AccessGate $gate,
        private readonly UpgradeMessageBuilder $builder,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);

        if ($this->gate->isAdmin($user)) {
            $payload = $this->builder->buildForAdmin($user);
            $bot->sendMessage(text: $payload['text']);

            return;
        }

        $sub = $this->subscriptions->getActiveSubscription($user);
        $status = $sub?->getStatus();

        if ($sub !== null && $status === SubscriptionStatus::Active) {
            $payload = $this->builder->buildForActivePro($user, $sub);
            $bot->sendMessage(text: $payload['text']);

            return;
        }

        if ($sub !== null && $status === SubscriptionStatus::Trialing) {
            $payload = $this->builder->buildForTrial($user, $sub);
            $bot->sendMessage(
                text: $payload['text'],
                reply_markup: $this->arrayKeyboardToNutgram($payload['keyboard']),
            );

            return;
        }

        // Free / cancelled-but-active / expired / нет подписки — единый pitch.
        $payload = $this->builder->buildForFree($user);
        $bot->sendMessage(
            text: $payload['text'],
            reply_markup: $this->arrayKeyboardToNutgram($payload['keyboard']),
        );
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
