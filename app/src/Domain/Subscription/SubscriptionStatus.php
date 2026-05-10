<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';      // активный триал
    case Active = 'active';           // оплачена, действует
    case PastDue = 'past_due';        // не удалось списать продление, в гонке
    case Cancelled = 'cancelled';     // юзер отменил, действует до конца периода
    case Expired = 'expired';         // период истёк

    /**
     * Подписка ещё даёт доступ. Cancelled тут — пользователь уже нажал
     * отмену, но currentPeriodEnd ещё не наступил, доступ действует.
     */
    public function isUsable(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::Cancelled => true,
            self::PastDue, self::Expired => false,
        };
    }
}
