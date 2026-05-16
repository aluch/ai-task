<?php

declare(strict_types=1);

namespace App\Telegram\UI;

use App\Domain\Subscription\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\PlanCatalog;
use App\Service\Subscription\UsageTracker;

/**
 * Тексты + клавиатуры для /subscription. Четыре варианта (active / trial /
 * cancelled / free) + admin-вариант. Возвращаем {text, keyboard} в формате
 * Bot API — это упрощает unit/smoke-проверку контента без поднятия Nutgram.
 */
final class SubscriptionMessageBuilder
{
    public function __construct(
        private readonly PlanCatalog $catalog,
        private readonly UsageTracker $usage,
    ) {
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>|null}
     */
    public function buildForAdmin(User $user): array
    {
        $text = <<<'TXT'
            👑 Админ

            Безлимитный доступ — без подписки и лимитов.

            Если нужно протестировать тарифы — /admin grant_trial или /admin grant_pro.
            TXT;

        return ['text' => $text, 'keyboard' => null];
    }

    /**
     * Active Pro с включённым auto-rebill. Показываем цену следующего
     * списания (она же — для пользователя «следующий платёж»).
     *
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildForActivePro(User $user, Subscription $sub, \DateTimeImmutable $now): array
    {
        $until = $this->fmtDate($sub->getCurrentPeriodEnd(), $user);
        $used = $this->usedProActions($user, $now);
        $proLimit = $this->catalog->actionLimit(Plan::Pro);
        $priceRub = (int) round($this->catalog->priceRubMinor(Plan::Pro) / 100);

        $text = <<<TXT
            💎 Pro

            Статус: активна
            Следующее списание: {$until} — {$priceRub} ₽
            Использовано в этом месяце: {$used} / {$proLimit}
            TXT;

        $row = [
            ['text' => '❌ Отменить автопродление', 'callback_data' => 'subscription:disable_rebill'],
        ];

        return ['text' => $text, 'keyboard' => [$row]];
    }

    /**
     * Active Pro, но auto-rebill отключён. Подписка работает до
     * currentPeriodEnd, дальше Free, если не возобновишь.
     *
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildForActiveProRebillOff(User $user, Subscription $sub, \DateTimeImmutable $now): array
    {
        $until = $this->fmtDate($sub->getCurrentPeriodEnd(), $user);
        $used = $this->usedProActions($user, $now);
        $proLimit = $this->catalog->actionLimit(Plan::Pro);

        $text = <<<TXT
            💎 Pro (автопродление отключено)

            Действует до: {$until}
            Дальше — Free, если не возобновишь.

            Использовано: {$used} / {$proLimit}
            TXT;

        $keyboard = [
            [
                ['text' => '✅ Возобновить автопродление', 'callback_data' => 'subscription:enable_rebill'],
            ],
            [
                ['text' => '💎 Оплатить ещё месяц', 'callback_data' => 'upgrade:info'],
            ],
        ];

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildForTrial(User $user, Subscription $sub, \DateTimeImmutable $now): array
    {
        $trialEnd = $sub->getTrialEndsAt() ?? $sub->getCurrentPeriodEnd();
        $until = $this->fmtDate($trialEnd, $user);
        $daysLeft = $this->daysLeftCeil($trialEnd, $now);
        $used = $this->usedProActions($user, $now);
        $proLimit = $this->catalog->actionLimit(Plan::Pro);

        $text = <<<TXT
            🎁 Триал Pro

            Заканчивается: {$until} (через {$daysLeft} {$this->plural($daysLeft, 'день', 'дня', 'дней')})
            Использовано: {$used} / {$proLimit}

            Хочешь продлить как Pro?
            TXT;

        $keyboard = [
            [['text' => '💎 Перейти на Pro', 'callback_data' => 'upgrade:info']],
        ];

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildForCancelled(User $user, Subscription $sub, \DateTimeImmutable $now): array
    {
        $until = $this->fmtDate($sub->getCurrentPeriodEnd(), $user);
        $used = $this->usedProActions($user, $now);
        $proLimit = $this->catalog->actionLimit(Plan::Pro);

        $text = <<<TXT
            💎 Pro (отменена)

            Действует до: {$until}
            Дальше — Free.

            Использовано: {$used} / {$proLimit}
            TXT;

        $keyboard = [
            [['text' => '💎 Возобновить подписку', 'callback_data' => 'upgrade:info']],
        ];

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildForFree(User $user, \DateTimeImmutable $now): array
    {
        $used = $this->usedFreeActions($user, $now);
        $freeLimit = $this->catalog->actionLimit(Plan::Free);
        $reset = $this->usage->getNextResetAt($user, $now);
        $resetText = $reset !== null ? $this->fmtDate($reset, $user) : '—';
        $price = (int) round($this->catalog->priceRubMinor(Plan::Pro) / 100);
        $priceFmt = number_format($price, 0, '.', ' ');
        $proLimit = $this->catalog->actionLimit(Plan::Pro);

        $text = <<<TXT
            🆓 Free

            Использовано: {$used} / {$freeLimit} в скользящий месяц
            Лимит обновится: {$resetText}

            Хочешь больше? Pro — ₽{$priceFmt}/мес, {$proLimit} действий + все интеграции.
            TXT;

        $keyboard = [
            [['text' => '💎 Узнать про Pro', 'callback_data' => 'upgrade:info']],
        ];

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    /**
     * Confirm-экран для «отключить автопродление». Это мягкая отмена:
     * подписка остаётся active до currentPeriodEnd, после этого Free.
     * Старый hard-cancel (status=Cancelled) был объединён с этим flow.
     *
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public function buildDisableRebillConfirm(User $user, Subscription $sub): array
    {
        $until = $this->fmtDate($sub->getCurrentPeriodEnd(), $user);
        $text = <<<TXT
            ⚠️ Точно отключить автопродление?

            Доступ Pro останется до {$until}, дальше — переход на Free.
            Списание не произойдёт.
            TXT;

        $keyboard = [
            [
                ['text' => '✅ Да, отключить', 'callback_data' => 'subscription:disable_rebill:confirm'],
                ['text' => '❌ Нет', 'callback_data' => 'subscription:disable_rebill:abort'],
            ],
        ];

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    public function buildDisableRebillDone(User $user, Subscription $sub): string
    {
        $until = $this->fmtDate($sub->getCurrentPeriodEnd(), $user);

        return "✅ Автопродление отключено. Доступ Pro действует до {$until}, дальше — Free.\n\n"
            . 'Если передумаешь — /subscription → «Возобновить автопродление».';
    }

    public function buildEnableRebillDone(User $user, Subscription $sub): string
    {
        $until = $this->fmtDate($sub->getCurrentPeriodEnd(), $user);

        return "✅ Автопродление включено. Следующее списание — {$until}.";
    }

    private function fmtDate(\DateTimeImmutable $at, User $user): string
    {
        return $at->setTimezone(new \DateTimeZone($user->getTimezone()))->format('d.m.Y');
    }

    private function daysLeftCeil(\DateTimeImmutable $end, \DateTimeImmutable $now): int
    {
        $sec = $end->getTimestamp() - $now->getTimestamp();
        if ($sec <= 0) {
            return 0;
        }

        return (int) ceil($sec / 86400);
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }

    /**
     * Прочитать proActionsCount из UsageCounter без записи. Делаем
     * через UsageTracker (он уже знает про периоды), вычисляя
     * limit - remaining = used.
     */
    private function usedProActions(User $user, \DateTimeImmutable $now): int
    {
        if ($user->isAdmin()) {
            return 0;
        }
        $limit = $this->catalog->actionLimit(Plan::Pro);
        $remaining = $this->usage->getRemainingActions($user, $now);

        return max(0, $limit - $remaining);
    }

    private function usedFreeActions(User $user, \DateTimeImmutable $now): int
    {
        if ($user->isAdmin()) {
            return 0;
        }
        $limit = $this->catalog->actionLimit(Plan::Free);
        $remaining = $this->usage->getRemainingActions($user, $now);

        return max(0, $limit - $remaining);
    }
}
