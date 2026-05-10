<?php

declare(strict_types=1);

namespace App\Telegram\UI;

use App\Domain\Subscription\Plan;
use App\Entity\User;
use App\Service\PlanCatalog;
use App\Service\Subscription\SubscriptionService;
use App\Service\Subscription\UsageTracker;

/**
 * Soft block UX: текст + клавиатура для пользователя, у которого
 * исчерпан лимит действий. Изолировано чтобы:
 *  - перевод/ребрендинг трогали один файл;
 *  - smoke-тесты могли проверить контент без поднятия Nutgram.
 *
 * Возвращает структуру {text, keyboard}, где keyboard — массив рядов
 * inline-кнопок в формате Telegram Bot API (см. TelegramNotifier).
 */
final class SoftBlockMessageBuilder
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly UsageTracker $usage,
        private readonly PlanCatalog $catalog,
    ) {
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function build(User $user, \DateTimeImmutable $now): array
    {
        $plan = $this->subscriptions->getCurrentPlan($user);
        $resetAt = $this->usage->getNextResetAt($user, $now);
        $userTz = new \DateTimeZone($user->getTimezone());

        $resetText = $resetAt !== null
            ? $resetAt->setTimezone($userTz)->format('d.m')
            : 'позже';

        $planLabel = match ($plan) {
            Plan::Free => 'Free',
            Plan::Pro => 'Pro',
        };

        $proLimit = $this->catalog->actionLimit(Plan::Pro);

        $text = <<<TXT
            🔒 Ты использовал лимит действий тарифа {$planLabel}.

            Лимит обновится {$resetText}.

            С Pro — {$proLimit} действий в месяц без ограничений.
            TXT;

        $keyboard = [
            [
                ['text' => '💎 Узнать про Pro', 'callback_data' => 'upgrade:info'],
            ],
        ];

        return ['text' => $text, 'keyboard' => $keyboard];
    }
}
