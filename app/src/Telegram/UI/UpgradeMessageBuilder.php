<?php

declare(strict_types=1);

namespace App\Telegram\UI;

use App\Domain\Subscription\Plan;
use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\PlanCatalog;

/**
 * Тексты + клавиатуры для /upgrade. Четыре варианта по статусу
 * пользователя: admin / active Pro / trialing / free.
 *
 * Возвращает {text, keyboard} в формате Telegram Bot API
 * (массив рядов inline-кнопок). Сборка через массивы, а не через
 * Nutgram-классы, чтобы builder можно было прогонять в smoke-тестах
 * без поднятия Nutgram-инстанса.
 */
final class UpgradeMessageBuilder
{
    public function __construct(
        private readonly PlanCatalog $catalog,
    ) {
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>|null}
     */
    public function buildForAdmin(User $user): array
    {
        $text = <<<TXT
            👑 Ты админ — у тебя безлимитный доступ.

            Если хочешь протестировать триал или Pro — используй /admin grant_trial или /admin grant_pro.
            TXT;

        return ['text' => $text, 'keyboard' => null];
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>|null}
     */
    public function buildForActivePro(User $user, Subscription $subscription): array
    {
        $proLimit = $this->catalog->actionLimit(Plan::Pro);
        $until = $subscription->getCurrentPeriodEnd()
            ->setTimezone(new \DateTimeZone($user->getTimezone()))
            ->format('d.m.Y');

        $text = <<<TXT
            💎 У тебя уже активна Pro-подписка

            Действует до: {$until}

            Все {$proLimit} действий в месяц без ограничений.

            Управление подпиской: /subscription
            TXT;

        return ['text' => $text, 'keyboard' => null];
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildForTrial(User $user, Subscription $subscription): array
    {
        $price = $this->formatPrice();
        $until = ($subscription->getTrialEndsAt() ?? $subscription->getCurrentPeriodEnd())
            ->setTimezone(new \DateTimeZone($user->getTimezone()))
            ->format('d.m.Y');

        $text = <<<TXT
            🎁 Сейчас идёт триал Pro

            Заканчивается: {$until}

            Хочешь оформить полную Pro-подписку прямо сейчас?
            Цена: ₽{$price}/мес (~\$5.40)

            Что входит:
            ✓ 1500 действий в месяц
            ✓ Все будущие интеграции
            ✓ Личная поддержка от автора
            TXT;

        return ['text' => $text, 'keyboard' => $this->payKeyboard($price)];
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildForFree(User $user): array
    {
        $price = $this->formatPrice();
        $freeLimit = $this->catalog->actionLimit(Plan::Free);
        $proLimit = $this->catalog->actionLimit(Plan::Pro);

        $text = <<<TXT
            💎 Pro-подписка для Помни

            Free → Pro:
            {$freeLimit} действий/мес → {$proLimit} действий/мес

            Что входит в Pro:
            ✓ {$proLimit} AI-действий в месяц
            ✓ Все будущие интеграции (VK, Email и т.п.)
            ✓ Личная поддержка от автора

            Цена: ₽{$price}/мес (~\$5.40)
            TXT;

        return ['text' => $text, 'keyboard' => $this->payKeyboard($price)];
    }

    /**
     * Текст-заглушка для кнопки «💳 Оплатить» — S3 пока без реальных
     * платежей, S4 заменит этот ответ на ЮKassa-инвойс.
     */
    public function buildPayStub(): string
    {
        return <<<'TXT'
            ⏳ Оплата через Telegram Payments скоро появится.

            Я уже регистрируюсь в платёжной системе. Когда всё готово — пришлю в этот чат.

            Если очень нужно прямо сейчас — напиши /admin (если ты админ) или дождись обновления.
            TXT;
    }

    public function buildLaterAck(): string
    {
        return 'ОК, без проблем. Когда захочешь — /upgrade.';
    }

    /**
     * Префикс «уже Pro» для подписчиков, для которых /subscription
     * показывает текст без кнопок (используется и в SubscriptionMessageBuilder).
     */
    public function isProActive(Subscription $sub): bool
    {
        return $sub->getStatus() === SubscriptionStatus::Active
            && $sub->getPlan() === Plan::Pro;
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function payKeyboard(string $price): array
    {
        return [
            [
                ['text' => "💳 Оплатить ₽{$price}", 'callback_data' => 'upgrade:pay'],
                ['text' => '❌ Не сейчас', 'callback_data' => 'upgrade:later'],
            ],
        ];
    }

    private function formatPrice(): string
    {
        // priceRubMinor: копейки. Округляем до рублей, формат с тонким
        // пробелом-разделителем тысяч («1 500»), без копеек.
        $rubles = (int) round($this->catalog->priceRubMinor(Plan::Pro) / 100);

        return number_format($rubles, 0, '.', ' ');
    }
}
