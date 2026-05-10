<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Тарифные планы. Архитектура plan-aware — добавление Standard / Family
 * в будущем не потребует переписывания core-логики, только новый case
 * + строки в config/packages/subscription.yaml.
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';

    public function isPaid(): bool
    {
        return match ($this) {
            self::Free => false,
            self::Pro => true,
        };
    }
}
