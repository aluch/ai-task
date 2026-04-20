<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Маркерное сообщение: «пора проверить дедлайны и разослать напоминания».
 * Отправляется раз в минуту из `DeadlineReminderSchedule`.
 */
final class CheckDeadlineRemindersMessage
{
}
