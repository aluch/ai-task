<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Маркерное сообщение: «найди истёкшие usable-подписки и переведи в
 * expired». Диспатчится из ReminderSchedule.
 */
final class ExpireSubscriptionsMessage
{
}
