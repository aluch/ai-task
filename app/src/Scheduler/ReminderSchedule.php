<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\CheckDeadlineRemindersMessage;
use App\Message\CheckPeriodicRemindersMessage;
use App\Message\CheckSnoozeWakeupsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Ежеминутный Scheduler-план напоминаний. Три recurring message'а:
 *
 *   1. CheckDeadlineRemindersMessage — напоминания о приближающемся дедлайне
 *      (Тип А). Ставит deadlineReminderSentAt чтобы не слать повторно.
 *
 *   2. CheckPeriodicRemindersMessage — периодические «пинки» по задачам без
 *      дедлайна (Тип Б). Использует reminderIntervalMinutes + lastRemindedAt
 *      как скользящее окно.
 *
 *   3. CheckSnoozeWakeupsMessage — пробуждение SNOOZED задач по истечении
 *      snoozedUntil (Тип В). После успешной отправки переводит задачу
 *      в PENDING. Без Scheduler'а — задача осталась бы SNOOZED до первой
 *      выборки списка.
 *
 * Имя 'reminders' даёт транспорт 'scheduler_reminders' автоматически.
 */
#[AsSchedule('reminders')]
final class ReminderSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 minute', new CheckDeadlineRemindersMessage()))
            ->add(RecurringMessage::every('1 minute', new CheckPeriodicRemindersMessage()))
            ->add(RecurringMessage::every('1 minute', new CheckSnoozeWakeupsMessage()));
    }
}
