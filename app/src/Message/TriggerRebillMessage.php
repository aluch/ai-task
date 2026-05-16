<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Сообщение, которое ReminderSchedule диспатчит каждые 15 минут.
 * Запускает RebillScheduler::run — 4 фазы recurring-биллинга
 * (notify-24h, initiate, retry, expire).
 */
final class TriggerRebillMessage
{
}
