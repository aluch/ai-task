<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\CheckDeadlineRemindersMessage;
use App\Message\CheckPeriodicRemindersMessage;
use App\Message\CheckSingleRemindersMessage;
use App\Message\CheckSnoozeWakeupsMessage;
use App\Message\ExpireSubscriptionsMessage;
use App\Message\NotifyTrialEndingMessage;
use App\Message\TriggerRebillMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Ежеминутный Scheduler-план напоминаний. Четыре recurring message'а:
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
 *      в PENDING.
 *
 *   4. CheckSingleRemindersMessage — одноразовое напоминание на точный
 *      момент (Тип Г). Использует singleReminderAt + singleReminderSentAt.
 *      Задача остаётся активной — это «пинг», а не snooze.
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
            ->add(RecurringMessage::every('1 minute', new CheckSnoozeWakeupsMessage()))
            ->add(RecurringMessage::every('1 minute', new CheckSingleRemindersMessage()))
            // Подписочные тики реже — это не пользовательские напоминания,
            // а фоновое обслуживание (триальные предупреждения / истечения).
            // Окна notify-проверок (60h..72h, 12h..24h) с запасом ловят
            // 5-минутный шаг.
            ->add(RecurringMessage::every('5 minutes', new NotifyTrialEndingMessage()))
            ->add(RecurringMessage::every('5 minutes', new ExpireSubscriptionsMessage()))
            // S5 recurring billing — каждые 15 минут. Окна шире (±1 час
            // для initiate, 23-25 часов для notify-24h), 15-минутный шаг
            // ловит их с двойным запасом.
            ->add(RecurringMessage::every('15 minutes', new TriggerRebillMessage()));
    }
}
