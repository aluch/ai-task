<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Маркерное сообщение: «пора пинать пользователя по залежавшимся задачам».
 * Отправляется раз в минуту из `ReminderSchedule`. Обрабатывается
 * `CheckPeriodicRemindersHandler`.
 */
final class CheckPeriodicRemindersMessage
{
}
