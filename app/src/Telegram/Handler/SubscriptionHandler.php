<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Subscription\SubscriptionStatus;
use App\Service\AccessGate;
use App\Service\Subscription\SubscriptionService;
use App\Service\TelegramUserResolver;
use App\Telegram\UI\SubscriptionMessageBuilder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * /subscription — экран статуса подписки. Текст + кнопки строит
 * SubscriptionMessageBuilder, тут — только роутинг по статусу.
 *
 * Для админа — особый экран без подписочной риторики (как и в /upgrade).
 */
class SubscriptionHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly SubscriptionService $subscriptions,
        private readonly AccessGate $gate,
        private readonly SubscriptionMessageBuilder $builder,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($this->gate->isAdmin($user)) {
            $payload = $this->builder->buildForAdmin($user);
            $bot->sendMessage(text: $payload['text']);

            return;
        }

        $sub = $this->subscriptions->getActiveSubscription($user);
        if ($sub === null) {
            $payload = $this->builder->buildForFree($user, $now);
            $this->send($bot, $payload);

            return;
        }

        match ($sub->getStatus()) {
            SubscriptionStatus::Active => $this->send(
                $bot,
                $sub->isAutoRebillEnabled()
                    ? $this->builder->buildForActivePro($user, $sub, $now)
                    : $this->builder->buildForActiveProRebillOff($user, $sub, $now),
            ),
            SubscriptionStatus::Trialing => $this->send($bot, $this->builder->buildForTrial($user, $sub, $now)),
            SubscriptionStatus::Cancelled => $this->send($bot, $this->builder->buildForCancelled($user, $sub, $now)),
            default => $this->send($bot, $this->builder->buildForFree($user, $now)),
        };
    }

    /**
     * @param array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>|null} $payload
     */
    private function send(Nutgram $bot, array $payload): void
    {
        $bot->sendMessage(
            text: $payload['text'],
            reply_markup: $payload['keyboard'] === null
                ? null
                : $this->arrayKeyboardToNutgram($payload['keyboard']),
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
