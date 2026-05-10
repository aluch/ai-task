<?php

declare(strict_types=1);

namespace App\Telegram\UI;

use App\Domain\Subscription\Plan;
use App\Service\PlanCatalog;

/**
 * Форматирует выходные данные SubscriptionStatsService в человекочитаемый
 * текст для /admin stats.
 */
final class AdminStatsMessageBuilder
{
    public function __construct(
        private readonly PlanCatalog $catalog,
    ) {
    }

    /**
     * @param array{
     *   users: array{total: int, allowed: int, admins: int},
     *   subscriptions: array{trialing: int, active: int, cancelled: int, expired: int},
     *   mrr_rub_minor: int,
     *   last_7d: array{new_users: int, started_trials: int, converted: int, cancellations: int},
     * } $stats
     */
    public function build(array $stats): string
    {
        $u = $stats['users'];
        $s = $stats['subscriptions'];
        $w = $stats['last_7d'];

        $mrrRub = (int) round($stats['mrr_rub_minor'] / 100);
        $mrrFmt = number_format($mrrRub, 0, '.', ' ');
        $proPriceRub = (int) round($this->catalog->priceRubMinor(Plan::Pro) / 100);
        $proPriceFmt = number_format($proPriceRub, 0, '.', ' ');

        return <<<TXT
            📊 Статистика подписок

            Пользователи:
            • Всего: {$u['total']}
            • Allowed: {$u['allowed']}
            • Admins: {$u['admins']}

            Подписки:
            • Trial: {$s['trialing']}
            • Active Pro: {$s['active']}
            • Cancelled (ещё активны): {$s['cancelled']}
            • Expired: {$s['expired']}

            MRR (Monthly Recurring Revenue):
            • {$mrrFmt} ₽/мес
              (рассчитан: число активных Pro × ₽{$proPriceFmt})

            За последние 7 дней:
            • Новые регистрации: {$w['new_users']}
            • Стартовало триалов: {$w['started_trials']}
            • Конвертация триал → Pro: {$w['converted']}
            • Отмен: {$w['cancellations']}
            TXT;
    }
}
