<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\CheckDeadlineRemindersMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Планирует ежеминутную проверку дедлайнов. Symfony Scheduler превращает
 * это в сообщения Messenger, которые воркер (сервис `scheduler` в docker-compose)
 * забирает и запускает CheckDeadlineRemindersHandler.
 *
 * Имя 'reminders' даёт транспорт 'scheduler_reminders' автоматически.
 */
#[AsSchedule('reminders')]
final class DeadlineReminderSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every('1 minute', new CheckDeadlineRemindersMessage()),
        );
    }
}
