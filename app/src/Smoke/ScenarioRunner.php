<?php

declare(strict_types=1);

namespace App\Smoke;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskSource;
use App\Enum\TaskStatus;
use App\Notification\ReminderSender;
use App\Notification\SendResult;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Набор сценарных smoke-тестов для reminder pipeline. Каждый сценарий:
 *   1. Через SmokeHarness сбрасывает состояние (reset + ensureTestUser).
 *   2. Замораживает «сейчас» на известный момент (FrozenClock).
 *   3. Создаёт нужные задачи прямо в БД (без AI — чтобы быть детерминированным).
 *   4. Вызывает ReminderSender/handler с фиксированным now.
 *   5. Валидирует выход (SendResult, состояние задачи в БД, количество сообщений
 *      в InMemoryTelegramNotifier).
 *
 * Каждый возвращает ScenarioResult с ✅ PASS или ❌ FAIL + объяснением.
 */
final class ScenarioRunner
{
    /** @var array<string, callable(): ScenarioResult> */
    private array $scenarios;

    public function __construct(
        private readonly SmokeHarness $harness,
        private readonly ManagerRegistry $doctrine,
        private readonly ReminderSender $sender,
    ) {
        $this->scenarios = [
            'deadline-short' => fn () => $this->deadlineShort(),
            'deadline-quiet-hours' => fn () => $this->deadlineQuietHours(),
            'deadline-recently-active' => fn () => $this->deadlineRecentlyActive(),
            'deadline-short-ignores-recent' => fn () => $this->deadlineShortIgnoresRecent(),
            'periodic-first-reminder' => fn () => $this->periodicFirstReminder(),
            'periodic-in-progress-doubled' => fn () => $this->periodicInProgressDoubled(),
            'snooze-wakeup-success' => fn () => $this->snoozeWakeupSuccess(),
            'snooze-wakeup-quiet-hours' => fn () => $this->snoozeWakeupQuietHours(),
        ];
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->scenarios);
    }

    public function run(string $name): ScenarioResult
    {
        if (!isset($this->scenarios[$name])) {
            return ScenarioResult::fail($name, 0.0, 'unknown scenario');
        }
        $t0 = microtime(true);
        try {
            $result = ($this->scenarios[$name])();

            return new ScenarioResult(
                name: $name,
                passed: $result->passed,
                message: $result->message,
                elapsedSeconds: microtime(true) - $t0,
            );
        } catch (\Throwable $e) {
            return ScenarioResult::fail($name, microtime(true) - $t0, 'exception: ' . $e->getMessage());
        }
    }

    /**
     * Дедлайн через +5 минут, remind_before=5. В момент now напоминание должно
     * уже триггернуться (deadline − 5 мин == now), и пользователь НЕ активен.
     */
    private function deadlineShort(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 10:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Deadline short', [
            'deadline' => $now->modify('+5 minutes'),
            'remindBeforeDeadlineMinutes' => 5,
            'priority' => TaskPriority::HIGH,
        ]);

        $result = $this->sender->sendDeadlineReminder($task);

        if ($result !== SendResult::SENT) {
            return ScenarioResult::fail('deadline-short', 0, "expected SENT, got {$result->value}");
        }

        $fresh = $this->harness->refreshTask($task->getId());
        if ($fresh?->getDeadlineReminderSentAt() === null) {
            return ScenarioResult::fail('deadline-short', 0, 'deadlineReminderSentAt не проставлен');
        }

        if ($this->harness->notifier()->count() !== 1) {
            return ScenarioResult::fail('deadline-short', 0, 'ожидался 1 message, получено ' . $this->harness->notifier()->count());
        }

        return ScenarioResult::pass('deadline-short', 0);
    }

    /**
     * Тихие часы (now=03:00 локального времени пользователя) → SKIPPED_QUIET_HOURS.
     */
    private function deadlineQuietHours(): ScenarioResult
    {
        // now = 00:00 UTC → 03:00 Europe/Tallinn → внутри quiet [22, 8)
        $setup = $this->setupFresh('2026-06-15 00:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Deadline during quiet', [
            'deadline' => $now->modify('+10 minutes'),
            'remindBeforeDeadlineMinutes' => 30,
            'priority' => TaskPriority::HIGH,
        ]);

        $result = $this->sender->sendDeadlineReminder($task);

        if ($result !== SendResult::SKIPPED_QUIET_HOURS) {
            return ScenarioResult::fail('deadline-quiet-hours', 0, "expected SKIPPED_QUIET_HOURS, got {$result->value}");
        }

        $fresh = $this->harness->refreshTask($task->getId());
        if ($fresh?->getDeadlineReminderSentAt() !== null) {
            return ScenarioResult::fail('deadline-quiet-hours', 0, 'deadlineReminderSentAt не должен ставиться при skip');
        }

        if ($this->harness->notifier()->count() !== 0) {
            return ScenarioResult::fail('deadline-quiet-hours', 0, 'messages должны быть 0, получено ' . $this->harness->notifier()->count());
        }

        return ScenarioResult::pass('deadline-quiet-hours', 0);
    }

    /**
     * Пользователь писал 2 минуты назад → SKIPPED_RECENTLY_ACTIVE. Затем
     * продвигаем now на +4 мин (прошло > 5) → SENT.
     */
    private function deadlineRecentlyActive(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 10:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $user->setLastMessageAt($now->modify('-2 minutes'));
        $this->doctrine->getManager()->flush();

        $task = $this->createTask($user, 'Deadline while user active', [
            'deadline' => $now->modify('+30 minutes'),
            'remindBeforeDeadlineMinutes' => 60,
            'priority' => TaskPriority::HIGH,
        ]);

        $first = $this->sender->sendDeadlineReminder($task);
        if ($first !== SendResult::SKIPPED_RECENTLY_ACTIVE) {
            return ScenarioResult::fail('deadline-recently-active', 0, "expected SKIPPED_RECENTLY_ACTIVE, got {$first->value}");
        }

        // Теперь продвинем clock на +4 минуты (итого с lastMessageAt прошло 6 мин).
        $this->harness->clock()?->advance('+4 minutes');

        $second = $this->sender->sendDeadlineReminder($task);
        if ($second !== SendResult::SENT) {
            return ScenarioResult::fail('deadline-recently-active', 0, "after pause expected SENT, got {$second->value}");
        }

        return ScenarioResult::pass('deadline-recently-active', 0);
    }

    /**
     * Пользователь активен прямо сейчас, но remind_before=3 (<5) → фильтр
     * пропускается → SENT.
     */
    private function deadlineShortIgnoresRecent(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 10:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $user->setLastMessageAt($now->modify('-30 seconds'));
        $this->doctrine->getManager()->flush();

        $task = $this->createTask($user, 'Short reminder bypass', [
            'deadline' => $now->modify('+3 minutes'),
            'remindBeforeDeadlineMinutes' => 3,
            'priority' => TaskPriority::URGENT,
        ]);

        $result = $this->sender->sendDeadlineReminder($task);
        if ($result !== SendResult::SENT) {
            return ScenarioResult::fail('deadline-short-ignores-recent', 0, "expected SENT, got {$result->value}");
        }

        return ScenarioResult::pass('deadline-short-ignores-recent', 0);
    }

    /**
     * Задача без дедлайна, reminder_interval=120, createdAt = now − 61 мин,
     * lastRemindedAt = null → первое напоминание должно прийти.
     */
    private function periodicFirstReminder(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 10:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Periodic first', [
            'reminderIntervalMinutes' => 120,
            'priority' => TaskPriority::HIGH,
            'createdAt' => $now->modify('-61 minutes'),
        ]);

        $result = $this->sender->sendPeriodicReminder($task);
        if ($result !== SendResult::SENT) {
            return ScenarioResult::fail('periodic-first-reminder', 0, "expected SENT, got {$result->value}");
        }

        $fresh = $this->harness->refreshTask($task->getId());
        if ($fresh?->getLastRemindedAt() === null) {
            return ScenarioResult::fail('periodic-first-reminder', 0, 'lastRemindedAt должен проставиться');
        }

        return ScenarioResult::pass('periodic-first-reminder', 0);
    }

    /**
     * Задача IN_PROGRESS с interval=120, lastRemindedAt = now − 125 мин.
     * Эффективный интервал x2 = 240 мин → 125 < 240 → кандидатом НЕ считается.
     * Продвигаем lastRemindedAt = now − 245 мин → кандидатом считается, SENT.
     */
    private function periodicInProgressDoubled(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 10:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Periodic in progress', [
            'reminderIntervalMinutes' => 120,
            'priority' => TaskPriority::HIGH,
            'status' => TaskStatus::IN_PROGRESS,
            'createdAt' => $now->modify('-300 minutes'),
            'lastRemindedAt' => $now->modify('-125 minutes'),
        ]);

        // Через findPeriodicReminderCandidates — задача НЕ должна попасть.
        $repo = $this->doctrine->getManager()->getRepository(Task::class);
        $candidates = array_filter(
            $repo->findPeriodicReminderCandidates($now),
            fn (Task $t) => $t->getId()->equals($task->getId()),
        );
        if ($candidates !== []) {
            return ScenarioResult::fail('periodic-in-progress-doubled', 0, 'IN_PROGRESS с last=−125м не должна быть кандидатом (ожид. x2=240)');
        }

        // Сдвигаем lastRemindedAt на -245 мин → кандидатом должна стать.
        $task->setLastRemindedAt($now->modify('-245 minutes'));
        $this->doctrine->getManager()->flush();

        $candidates2 = array_filter(
            $repo->findPeriodicReminderCandidates($now),
            fn (Task $t) => $t->getId()->equals($task->getId()),
        );
        if ($candidates2 === []) {
            return ScenarioResult::fail('periodic-in-progress-doubled', 0, 'IN_PROGRESS с last=−245м должна быть кандидатом');
        }

        return ScenarioResult::pass('periodic-in-progress-doubled', 0);
    }

    /**
     * Задача SNOOZED, snoozed_until в прошлом. sendSnoozeWakeup → SENT,
     * статус становится PENDING.
     */
    private function snoozeWakeupSuccess(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 10:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Snooze waking', [
            'status' => TaskStatus::SNOOZED,
            'snoozedUntil' => $now->modify('-1 minute'),
            'priority' => TaskPriority::MEDIUM,
        ]);

        $result = $this->sender->sendSnoozeWakeup($task);
        if ($result !== SendResult::SENT) {
            return ScenarioResult::fail('snooze-wakeup-success', 0, "expected SENT, got {$result->value}");
        }

        $fresh = $this->harness->refreshTask($task->getId());
        if ($fresh?->getStatus() !== TaskStatus::PENDING) {
            return ScenarioResult::fail('snooze-wakeup-success', 0, 'статус должен стать PENDING, сейчас ' . $fresh?->getStatus()->value);
        }
        if ($fresh->getSnoozedUntil() !== null) {
            return ScenarioResult::fail('snooze-wakeup-success', 0, 'snoozedUntil должен быть null после wake');
        }

        return ScenarioResult::pass('snooze-wakeup-success', 0);
    }

    /**
     * Та же задача, но сейчас тихие часы → SKIPPED_QUIET_HOURS, задача
     * остаётся SNOOZED (не реактивируется).
     */
    private function snoozeWakeupQuietHours(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 00:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Snooze waking at night', [
            'status' => TaskStatus::SNOOZED,
            'snoozedUntil' => $now->modify('-1 minute'),
            'priority' => TaskPriority::MEDIUM,
        ]);

        $result = $this->sender->sendSnoozeWakeup($task);
        if ($result !== SendResult::SKIPPED_QUIET_HOURS) {
            return ScenarioResult::fail('snooze-wakeup-quiet-hours', 0, "expected SKIPPED_QUIET_HOURS, got {$result->value}");
        }

        $fresh = $this->harness->refreshTask($task->getId());
        if ($fresh?->getStatus() !== TaskStatus::SNOOZED) {
            return ScenarioResult::fail('snooze-wakeup-quiet-hours', 0, 'задача должна остаться SNOOZED при skip, сейчас ' . $fresh?->getStatus()->value);
        }

        return ScenarioResult::pass('snooze-wakeup-quiet-hours', 0);
    }

    /**
     * @param array{0:int,1:int} $quietHours
     * @return array{user: User, now: \DateTimeImmutable}
     */
    private function setupFresh(string $nowIso, array $quietHours): array
    {
        $this->harness->reset();
        $this->harness->notifier()->clear();
        $user = $this->harness->ensureTestUser();
        $user->setQuietStartHour($quietHours[0]);
        $user->setQuietEndHour($quietHours[1]);
        $user->setLastMessageAt(null);
        $this->doctrine->getManager()->flush();

        $now = new \DateTimeImmutable($nowIso);
        $this->harness->freezeTimeAt($now);

        return ['user' => $user, 'now' => $now->setTimezone(new \DateTimeZone('UTC'))];
    }

    /**
     * Создаёт задачу с произвольными полями. Обходит #[PrePersist]
     * (createdAt/updatedAt) через прямое присваивание через рефлексию —
     * иначе «create at = now − 61m» не поставишь.
     *
     * @param array<string, mixed> $opts
     */
    private function createTask(User $user, string $title, array $opts = []): Task
    {
        $task = new Task($user, $title);
        $task->setSource(TaskSource::MANUAL);

        if (isset($opts['deadline'])) {
            $task->setDeadline($opts['deadline']);
        }
        if (isset($opts['remindBeforeDeadlineMinutes'])) {
            $task->setRemindBeforeDeadlineMinutes($opts['remindBeforeDeadlineMinutes']);
        }
        if (isset($opts['reminderIntervalMinutes'])) {
            $task->setReminderIntervalMinutes($opts['reminderIntervalMinutes']);
        }
        if (isset($opts['lastRemindedAt'])) {
            $task->setLastRemindedAt($opts['lastRemindedAt']);
        }
        if (isset($opts['priority'])) {
            $task->setPriority($opts['priority']);
        }
        if (isset($opts['status'])) {
            $task->setStatus($opts['status']);
        }
        if (isset($opts['snoozedUntil'])) {
            // snooze() ставит status=SNOOZED, а у нас уже может быть
            // явно задан status — используем прямое присваивание через setter.
            $refl = new \ReflectionProperty(Task::class, 'snoozedUntil');
            $refl->setValue($task, $opts['snoozedUntil']);
        }

        $em = $this->doctrine->getManager();
        $em->persist($task);
        $em->flush();

        // createdAt — нужно задавать после persist (PrePersist уже сработал).
        if (isset($opts['createdAt'])) {
            $refl = new \ReflectionProperty(Task::class, 'createdAt');
            $refl->setValue($task, $opts['createdAt']);
            $em->flush();
        }

        return $task;
    }
}
