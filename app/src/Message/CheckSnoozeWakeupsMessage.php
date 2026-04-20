<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Маркерное сообщение: «найди отложенные задачи, чей snoozedUntil истёк,
 * уведоми пользователя и переведи их в PENDING». Отправляется раз в минуту
 * из `ReminderSchedule`. Обрабатывается `CheckSnoozeWakeupsHandler`.
 */
final class CheckSnoozeWakeupsMessage
{
}
