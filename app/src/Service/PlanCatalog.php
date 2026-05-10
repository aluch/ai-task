<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Subscription\Plan;

/**
 * Абстрагирует config/packages/subscription.yaml от вызывающего кода.
 * limits/prices задаются через env-переменные, тут — типизированный
 * доступ к ним по Plan.
 */
class PlanCatalog
{
    /**
     * @param array<string, array{action_limit: int, price_rub_minor: int}> $plans
     */
    public function __construct(
        private readonly array $plans,
        private readonly int $trialDays,
        private readonly int $refundWindowDays,
    ) {
    }

    public function actionLimit(Plan $plan): int
    {
        return $this->plans[$plan->value]['action_limit']
            ?? throw new \LogicException("No action_limit for plan {$plan->value}");
    }

    /**
     * Цена в копейках (RUB minor). Для Free вернёт 0.
     */
    public function priceRubMinor(Plan $plan): int
    {
        return $this->plans[$plan->value]['price_rub_minor'] ?? 0;
    }

    public function trialDays(): int
    {
        return $this->trialDays;
    }

    public function refundWindowDays(): int
    {
        return $this->refundWindowDays;
    }
}
