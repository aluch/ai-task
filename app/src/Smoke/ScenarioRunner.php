<?php

declare(strict_types=1);

namespace App\Smoke;

use App\AI\Assistant;
use App\AI\ConfirmationExecutor;
use App\AI\ConversationHistoryStore;
use App\AI\DTO\HistoryMessage;
use App\AI\DTO\PendingAction;
use App\AI\PendingActionStore;
use App\Entity\Task;
use App\Entity\User;
use App\Service\AccessGate;
use App\Enum\TaskPriority;
use App\Enum\TaskSource;
use App\Enum\TaskStatus;
use App\Notification\ReminderSender;
use App\Notification\SendResult;
use Doctrine\Persistence\ManagerRegistry;

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

    /** Инкрементальный счётчик telegram_msg_id для имитации реальных id в smoke. */
    private int $msgIdCounter = 1000;

    public function __construct(
        private readonly SmokeHarness $harness,
        private readonly ManagerRegistry $doctrine,
        private readonly ReminderSender $sender,
        private readonly Assistant $assistant,
        private readonly ConversationHistoryStore $historyStore,
        private readonly PendingActionStore $pendingStore,
        private readonly ConfirmationExecutor $confirmExecutor,
        private readonly AccessGate $accessGate,
    ) {
        $this->scenarios = [
            // reminder-пайплайн
            'deadline-short' => fn () => $this->deadlineShort(),
            'deadline-quiet-hours' => fn () => $this->deadlineQuietHours(),
            'deadline-recently-active' => fn () => $this->deadlineRecentlyActive(),
            'deadline-short-ignores-recent' => fn () => $this->deadlineShortIgnoresRecent(),
            'periodic-first-reminder' => fn () => $this->periodicFirstReminder(),
            'periodic-in-progress-doubled' => fn () => $this->periodicInProgressDoubled(),
            'snooze-wakeup-success' => fn () => $this->snoozeWakeupSuccess(),
            'snooze-wakeup-quiet-hours' => fn () => $this->snoozeWakeupQuietHours(),
            'single-reminder-basic' => fn () => $this->singleReminderBasic(),
            'single-reminder-bypasses-quiet-hours' => fn () => $this->singleReminderBypassesQuietHours(),
            'single-reminder-respects-recently-active-except-short' => fn () => $this->singleReminderShortBypassesRecent(),
            // Assistant — тут дергаем реальный Claude API, медленнее
            'assistant-basic-flow' => fn () => $this->assistantBasicFlow(),
            'assistant-update-task' => fn () => $this->assistantUpdateTask(),
            'assistant-duplicate-prevention' => fn () => $this->assistantDuplicatePrevention(),
            'assistant-mark-done-ambiguous' => fn () => $this->assistantMarkDoneAmbiguous(),
            'assistant-block-tasks' => fn () => $this->assistantBlockTasks(),
            'assistant-suggest-tasks' => fn () => $this->assistantSuggestTasks(),
            'assistant-fuzzy-russian-match' => fn () => $this->assistantFuzzyRussianMatch(),
            'assistant-history-reference' => fn () => $this->assistantHistoryReference(),
            'assistant-reply-context' => fn () => $this->assistantReplyContext(),
            'assistant-history-ttl' => fn () => $this->assistantHistoryTtl(),
            'assistant-reset-command' => fn () => $this->assistantResetCommand(),
            'assistant-confirm-create-batch' => fn () => $this->assistantConfirmCreateBatch(),
            'assistant-confirm-cancel-no' => fn () => $this->assistantConfirmCancelNo(),
            'assistant-confirm-ttl-expired' => fn () => $this->assistantConfirmTtlExpired(),
            'assistant-no-confirm-for-single' => fn () => $this->assistantNoConfirmForSingle(),
            'whitelist-blocks-unknown-user' => fn () => $this->whitelistBlocksUnknown(),
            'whitelist-allows-after-approve' => fn () => $this->whitelistAllowsAfterApprove(),
            'admin-invite-creates-allowed-user' => fn () => $this->adminInviteCreatesAllowed(),
            'whitelist-rejected-cooldown' => fn () => $this->whitelistRejectedCooldown(),
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

    // ============================================================
    // Single-shot reminder (Тип Г) сценарии. Claude API не дёргают.
    // ============================================================

    /**
     * Задача создана, single_reminder_at через +1 мин. Продвигаем clock,
     * жмём tick — ожидаем SENT + singleReminderSentAt проставлен.
     */
    private function singleReminderBasic(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 12:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Single basic', [
            'singleReminderAt' => $now->modify('+1 minute'),
        ]);

        // До момента — кандидат ещё не должен быть возвращён
        $repo = $this->doctrine->getManager()->getRepository(Task::class);
        if ($repo->findSingleReminderCandidates($now) !== []) {
            return ScenarioResult::fail('single-reminder-basic', 0, 'до now кандидат не должен быть готов');
        }

        // Продвигаем на +2 мин — должен стать кандидатом и триггернуться
        $this->harness->clock()->advance('+2 minutes');
        $moved = $this->harness->clock()->now();
        $candidates = $repo->findSingleReminderCandidates($moved);
        if (count($candidates) !== 1) {
            return ScenarioResult::fail('single-reminder-basic', 0, 'после сдвига ожидался 1 кандидат, получено ' . count($candidates));
        }

        $result = $this->sender->sendSingleReminder($candidates[0]);
        if ($result !== SendResult::SENT) {
            return ScenarioResult::fail('single-reminder-basic', 0, "expected SENT, got {$result->value}");
        }

        $fresh = $this->harness->refreshTask($task->getId());
        if ($fresh?->getSingleReminderSentAt() === null) {
            return ScenarioResult::fail('single-reminder-basic', 0, 'singleReminderSentAt не проставлен');
        }

        return ScenarioResult::pass('single-reminder-basic', 0);
    }

    /**
     * now = 00:00 UTC → 03:00 Tallinn (quiet). single_reminder_respect_quiet_hours=false.
     * Ожидаем SENT (не SKIPPED_QUIET_HOURS).
     */
    private function singleReminderBypassesQuietHours(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 00:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $task = $this->createTask($user, 'Single at night', [
            'singleReminderAt' => $now->modify('-1 minute'), // уже «пора»
            'singleReminderRespectQuietHours' => false,
        ]);

        $result = $this->sender->sendSingleReminder($task);
        if ($result !== SendResult::SENT) {
            return ScenarioResult::fail('single-reminder-bypasses-quiet-hours', 0, "expected SENT, got {$result->value}");
        }

        // Зеркало: если поставить respectQuietHours=true — должно быть SKIPPED
        $this->harness->reset();
        $this->harness->notifier()->clear();
        $user2 = $this->harness->ensureTestUser();
        $user2->setQuietStartHour(22);
        $user2->setQuietEndHour(8);
        $user2->setLastMessageAt(null);
        $this->doctrine->getManager()->flush();
        $this->harness->freezeTimeAt(new \DateTimeImmutable('2026-06-15 00:00:00 UTC'));

        $task2 = $this->createTask($user2, 'Single at night — respect', [
            'singleReminderAt' => $this->harness->clock()->now()->modify('-1 minute'),
            'singleReminderRespectQuietHours' => true,
        ]);

        $result2 = $this->sender->sendSingleReminder($task2);
        if ($result2 !== SendResult::SKIPPED_QUIET_HOURS) {
            return ScenarioResult::fail('single-reminder-bypasses-quiet-hours', 0, "с respect=true ожидался SKIPPED_QUIET_HOURS, got {$result2->value}");
        }

        return ScenarioResult::pass('single-reminder-bypasses-quiet-hours', 0);
    }

    /**
     * Создаём задачу с singleReminderAt = createdAt + 3 минуты (короткий
     * таймер). Пользователь активен (lastMessageAt = now - 30s). Фильтр
     * recently_active должен быть пропущен → SENT.
     *
     * Зеркало: та же задача но с singleReminderAt = createdAt + 20 минут
     * (длинный таймер) → recently_active применяется → SKIPPED.
     */
    private function singleReminderShortBypassesRecent(): ScenarioResult
    {
        $setup = $this->setupFresh('2026-06-15 12:00:00 UTC', quietHours: [22, 8]);
        $now = $setup['now'];
        $user = $setup['user'];

        $user->setLastMessageAt($now->modify('-30 seconds'));
        $this->doctrine->getManager()->flush();

        // short: at - createdAt = 3 мин (< 10) → skip recently_active
        $shortTask = $this->createTask($user, 'Single short', [
            'singleReminderAt' => $now->modify('+3 minutes'),
        ]);
        // createdAt у новой задачи = сейчас (из PrePersist) — поэтому at - createdAt ≈ 3 мин.

        // Продвигаем clock на +4 мин, чтобы at уже прошёл; lastMessageAt всё ещё «недавний».
        $this->harness->clock()->advance('+4 minutes');

        $r1 = $this->sender->sendSingleReminder($shortTask);
        if ($r1 !== SendResult::SENT) {
            return ScenarioResult::fail(
                'single-reminder-respects-recently-active-except-short',
                0,
                "short: expected SENT (<10min bypass), got {$r1->value}",
            );
        }

        // Зеркало: длинный таймер — не должен обойти recently_active
        $this->harness->reset();
        $this->harness->notifier()->clear();
        $user2 = $this->harness->ensureTestUser();
        $user2->setQuietStartHour(22);
        $user2->setQuietEndHour(8);
        $this->harness->freezeTimeAt(new \DateTimeImmutable('2026-06-15 12:00:00 UTC'));
        $now2 = $this->harness->clock()->now();
        $user2->setLastMessageAt($now2->modify('-30 seconds'));
        $this->doctrine->getManager()->flush();

        $longTask = $this->createTask($user2, 'Single long', [
            'singleReminderAt' => $now2->modify('+20 minutes'),
        ]);
        $this->harness->clock()->advance('+21 minutes');
        // но lastMessageAt сдвигать не будем — он всё ещё -30s относительно ИСХОДНОГО;
        // обновлю чтобы оставался recent
        $refreshedUser = $this->doctrine->getManager()->find(\App\Entity\User::class, $user2->getId());
        $refreshedUser->setLastMessageAt($this->harness->clock()->now()->modify('-30 seconds'));
        $this->doctrine->getManager()->flush();

        $r2 = $this->sender->sendSingleReminder($longTask);
        if ($r2 !== SendResult::SKIPPED_RECENTLY_ACTIVE) {
            return ScenarioResult::fail(
                'single-reminder-respects-recently-active-except-short',
                0,
                "long: expected SKIPPED_RECENTLY_ACTIVE, got {$r2->value}",
            );
        }

        return ScenarioResult::pass('single-reminder-respects-recently-active-except-short', 0);
    }

    // ============================================================
    // Assistant-сценарии. Тут реально дергается Claude API — 2-5s
    // на каждый Assistant::handle.
    // ============================================================

    /**
     * Guard: минимальный поток создать → показать → закрыть → показать.
     * Если этот сценарий ломается — остальная логика Ассистента под угрозой.
     */
    private function assistantBasicFlow(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        // 1. create
        $this->runAssistant($user, 'Купить молоко');
        $tasks = $this->userTasks($user);
        if (count($tasks) !== 1) {
            return ScenarioResult::fail('assistant-basic-flow', 0, 'ожидалась 1 созданная задача, получено ' . count($tasks));
        }
        if (mb_stripos($tasks[0]->getTitle(), 'молок') === false) {
            return ScenarioResult::fail('assistant-basic-flow', 0, 'title не содержит «молок»: ' . $tasks[0]->getTitle());
        }

        // 2. list — в ответе должно упоминаться «молоко»
        $r = $this->runAssistant($user, 'Что у меня есть?');
        if (mb_stripos($r->replyText, 'молок') === false) {
            return ScenarioResult::fail('assistant-basic-flow', 0, 'list-ответ не упоминает «молок»: ' . mb_substr($r->replyText, 0, 120));
        }

        // 3. done
        $this->runAssistant($user, 'Молоко купил');
        $doneTasks = $this->userTasks($user, [TaskStatus::DONE]);
        if (count($doneTasks) !== 1) {
            return ScenarioResult::fail('assistant-basic-flow', 0, 'задача не перешла в DONE, done-count=' . count($doneTasks));
        }

        // 4. empty list — проверяем что активных нет
        $r2 = $this->runAssistant($user, 'Что у меня есть?');
        $activeAfter = $this->userTasks($user);
        if ($activeAfter !== []) {
            return ScenarioResult::fail('assistant-basic-flow', 0, 'после done активные остались: ' . count($activeAfter));
        }
        // reply на «что у меня есть» при пустом списке — проверяем что он не
        // упоминает «молок», а скорее «нет задач»/пусто.
        if (mb_stripos($r2->replyText, 'молок') !== false) {
            return ScenarioResult::fail('assistant-basic-flow', 0, 'empty-reply всё ещё упоминает «молок»');
        }

        return ScenarioResult::pass('assistant-basic-flow', 0);
    }

    private function assistantUpdateTask(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        // Создаём задачу без дедлайна. Используем одно и то же корневое
        // слово в создании и апдейте — fuzzy-поиск по корню «стрижк»
        // найдёт, даже если пользователь скажет «стрижка» vs «стрижку».
        $this->runAssistant($user, 'Записаться на стрижку в бербершоп');
        $tasks = $this->userTasks($user);
        if (count($tasks) !== 1) {
            return ScenarioResult::fail('assistant-update-task', 0, 'create: ожидалась 1 задача, получено ' . count($tasks));
        }
        $taskId = $tasks[0]->getId();

        // Проставляем deadline_reminder_sent_at руками, чтобы проверить что
        // апдейт дедлайна его сбрасывает.
        $tasks[0]->setDeadlineReminderSentAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->doctrine->getManager()->flush();

        // Просим обновить — слово «стрижк» найдётся через fuzzy-search.
        $r = $this->runAssistant($user, 'Перенеси стрижку на завтра 14:00');
        $fresh = $this->doctrine->getManager()->getRepository(Task::class)->find($taskId);
        if ($fresh === null) {
            return ScenarioResult::fail('assistant-update-task', 0, 'задача исчезла');
        }
        if ($fresh->getDeadline() === null) {
            return ScenarioResult::fail('assistant-update-task', 0, 'update не проставил дедлайн. reply=' . mb_substr($r->replyText, 0, 120));
        }
        if ($fresh->getDeadlineReminderSentAt() !== null) {
            return ScenarioResult::fail('assistant-update-task', 0, 'deadlineReminderSentAt должен был сброситься');
        }
        // убеждаемся что не создалась вторая задача
        $all = $this->userTasks($user);
        if (count($all) !== 1) {
            return ScenarioResult::fail('assistant-update-task', 0, 'создана лишняя задача вместо обновления, count=' . count($all));
        }

        return ScenarioResult::pass('assistant-update-task', 0);
    }

    /**
     * Создаём «Купить молоко», второй раз просим «купить молоко» — ассистент
     * должен заметить точный дубликат. Критерии:
     *  - не должно появиться 2 задачи с «молок» в активных,
     *  - reply НЕ должен содержать уточняющих вопросов вида
     *    «создать новую или обновить / подтверди / уточни» — у ассистента
     *    нет памяти между сообщениями, такие вопросы бессмысленны.
     */
    private function assistantDuplicatePrevention(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $this->runAssistant($user, 'Купить молоко');
        $r2 = $this->runAssistant($user, 'Купи молоко');

        $active = $this->userTasks($user);
        $dupCount = 0;
        foreach ($active as $t) {
            if (mb_stripos($t->getTitle(), 'молок') !== false) {
                $dupCount++;
            }
        }
        if ($dupCount > 1) {
            return ScenarioResult::fail('assistant-duplicate-prevention', 0, "создано {$dupCount} задач с «молок», ожидалась 1");
        }

        // Проверяем что ассистент не оставил пользователя в ожидании ответа
        // на вопрос. Ищем именно фразы-ожидания «подтверди?», «создать или
        // обновить?», «какую именно?», «создавать всё-таки?». Слова типа
        // «уточни» допустимы в смысле «уточни в следующем сообщении если
        // нужно другое» — это подсказка к новой команде, не вопрос.
        $forbidden = ['подтверди', 'создать новую или', 'новую или обнов', 'обновить или созд', 'какую именно', 'создавать всё', 'создать всё', 'создать всё-таки'];
        $replyLc = mb_strtolower($r2->replyText);
        foreach ($forbidden as $phrase) {
            if (mb_strpos($replyLc, $phrase) !== false) {
                return ScenarioResult::fail(
                    'assistant-duplicate-prevention',
                    0,
                    "reply содержит запрещённую уточняющую фразу «{$phrase}»: " . mb_substr($r2->replyText, 0, 180),
                );
            }
        }

        return ScenarioResult::pass('assistant-duplicate-prevention', 0);
    }

    /**
     * Две задачи с «звонк», сообщение «звонок сделал» неоднозначное —
     * ни одна задача НЕ должна быть DONE. Ассистент не должен гадать,
     * а показать оба варианта в reply — подсказка чтобы пользователь
     * переформулировал следующим сообщением (но без «?»-ожидания,
     * см. правило «нет памяти между сообщениями»).
     */
    private function assistantMarkDoneAmbiguous(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $em = $this->doctrine->getManager();
        $t1 = new Task($user, 'Позвонить Петру про звонок в страховую');
        $t2 = new Task($user, 'Сделать звонок в банк');
        $em->persist($t1);
        $em->persist($t2);
        $em->flush();

        $r = $this->runAssistant($user, 'Звонок сделал');
        $done = $this->userTasks($user, [TaskStatus::DONE]);
        if ($done !== []) {
            return ScenarioResult::fail('assistant-mark-done-ambiguous', 0, 'одна из задач ошибочно закрыта — должен был показать оба варианта');
        }
        // Оба варианта должны быть упомянуты: «страховую» и «банк» — хотя бы
        // фрагментом. Иначе ассистент утаил неоднозначность.
        $replyLc = mb_strtolower($r->replyText);
        $mentionsStrahov = mb_strpos($replyLc, 'страхов') !== false;
        $mentionsBank = mb_strpos($replyLc, 'банк') !== false;
        if (!$mentionsStrahov || !$mentionsBank) {
            return ScenarioResult::fail(
                'assistant-mark-done-ambiguous',
                0,
                'оба варианта должны быть в reply (страховая + банк). reply=' . mb_substr($r->replyText, 0, 180),
            );
        }

        return ScenarioResult::pass('assistant-mark-done-ambiguous', 0);
    }

    /**
     * Создаём 2 задачи, просим связать через block. Проверяем что
     * «вторая» → $blockedBy содержит «первую».
     */
    private function assistantBlockTasks(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $em = $this->doctrine->getManager();
        $blocker = new Task($user, 'Снять наличку');
        $blocked = new Task($user, 'Купить билеты на концерт');
        $em->persist($blocker);
        $em->persist($blocked);
        $em->flush();

        $r = $this->runAssistant(
            $user,
            'Чтобы купить билеты на концерт нужно сначала снять наличку — свяжи эти задачи',
        );

        $em->refresh($blocked);
        if (count($blocked->getActiveBlockers()) !== 1) {
            return ScenarioResult::fail('assistant-block-tasks', 0, 'связь не создана. reply=' . mb_substr($r->replyText, 0, 150));
        }

        return ScenarioResult::pass('assistant-block-tasks', 0);
    }

    /**
     * Создаём пару задач с разными эстимейтами. Просим посоветовать при
     * доступном времени — ожидаем вызов suggest_tasks и хоть одну задачу
     * в reply.
     */
    private function assistantSuggestTasks(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $em = $this->doctrine->getManager();
        $t1 = new Task($user, 'Помыть посуду');
        $t1->setEstimatedMinutes(20);
        $t2 = new Task($user, 'Разобрать почту');
        $t2->setEstimatedMinutes(30);
        $em->persist($t1);
        $em->persist($t2);
        $em->flush();

        $r = $this->runAssistant($user, 'Я дома, свободен минут 40 — что сделать?');

        if (!in_array('suggest_tasks', $r->toolsCalled, true)) {
            return ScenarioResult::fail('assistant-suggest-tasks', 0, 'tool suggest_tasks не вызван, вызваны: ' . implode(',', $r->toolsCalled));
        }

        // reply должен упомянуть хотя бы одну из задач
        $mentionsAny = mb_stripos($r->replyText, 'посуд') !== false
            || mb_stripos($r->replyText, 'почт') !== false;
        if (!$mentionsAny) {
            return ScenarioResult::fail('assistant-suggest-tasks', 0, 'reply не упоминает ни одну из задач');
        }

        return ScenarioResult::pass('assistant-suggest-tasks', 0);
    }

    /**
     * Пользовательский запрос в другой форме слова, чем title задачи
     * («пополнение» vs «Пополнить»). Старый стемминг обрезал ровно 2
     * символа и не ловил разные части речи. TaskMatcher через Haiku
     * должен справиться — но чтобы он реально вызывался, нужно ≥2 задач
     * (иначе срабатывает early-return «одна задача → вернуть её»).
     */
    private function assistantFuzzyRussianMatch(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $em = $this->doctrine->getManager();
        $target = new Task($user, 'Пополнить брокерский счёт ИИС');
        $decoy1 = new Task($user, 'Купить билеты на концерт');
        $decoy2 = new Task($user, 'Сходить в МФЦ за справкой');
        $em->persist($target);
        $em->persist($decoy1);
        $em->persist($decoy2);
        $em->flush();
        $targetId = $target->getId();
        $decoyIds = [$decoy1->getId(), $decoy2->getId()];

        $r = $this->runAssistant($user, 'закрой задачу про пополнение счёта ИИС');

        $freshTarget = $em->getRepository(Task::class)->find($targetId);
        if ($freshTarget === null) {
            return ScenarioResult::fail('assistant-fuzzy-russian-match', 0, 'target исчезла');
        }
        if ($freshTarget->getStatus() !== TaskStatus::DONE) {
            return ScenarioResult::fail(
                'assistant-fuzzy-russian-match',
                0,
                'целевая задача («Пополнить брокерский счёт ИИС») не помечена DONE, статус='
                . $freshTarget->getStatus()->value . '. reply=' . mb_substr($r->replyText, 0, 180),
            );
        }
        // Decoys должны остаться активными
        foreach ($decoyIds as $id) {
            $d = $em->getRepository(Task::class)->find($id);
            if ($d !== null && $d->getStatus() === TaskStatus::DONE) {
                return ScenarioResult::fail(
                    'assistant-fuzzy-russian-match',
                    0,
                    'ошибочно закрыта decoy-задача «' . $d->getTitle() . '»',
                );
            }
        }

        return ScenarioResult::pass('assistant-fuzzy-russian-match', 0);
    }

    /**
     * История диалога: после «Создай задачу купить молоко» сообщение
     * «Отложи её на завтра» без названия должно зацепиться за последнюю
     * обсуждаемую задачу через историю.
     */
    private function assistantHistoryReference(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $this->runAssistant($user, 'Создай задачу купить молоко');
        $r2 = $this->runAssistant($user, 'Отложи её на завтра 14:00');

        $tasks = $this->userTasks($user, [TaskStatus::SNOOZED]);
        if ($tasks === []) {
            return ScenarioResult::fail(
                'assistant-history-reference',
                0,
                'задача не отложена (по истории не нашёл какую). reply=' . mb_substr($r2->replyText, 0, 180),
            );
        }
        foreach ($tasks as $t) {
            if (mb_stripos($t->getTitle(), 'молок') !== false) {
                return ScenarioResult::pass('assistant-history-reference', 0);
            }
        }

        return ScenarioResult::fail(
            'assistant-history-reference',
            0,
            'отложена не та задача. snoozed: ' . implode(',', array_map(fn (Task $t) => $t->getTitle(), $tasks)),
        );
    }

    /**
     * Reply-механика: первое сообщение ассистента содержит вопрос или
     * инфо, второе — Reply на него. Блок <reply_context> должен пойти
     * в промпт. Проверяем простой кейс: создаём задачу «узнать ДР Ксюши»,
     * потом Reply с датой — ассистент должен понять что это ответ по
     * контексту.
     */
    private function assistantReplyContext(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $this->runAssistant($user, 'Создай задачу: узнать когда у Ксюши день рождения');
        $replyTarget = $this->lastAssistantMsgId;

        // Reply на сообщение бота с конкретной датой
        $r2 = $this->runAssistant($user, '2 мая', replyToMsgId: $replyTarget);

        // Reply_context должен был пойти в промпт — проверим через лог (хотя
        // логи в smoke не кэшируются просто). Минимальная проверка: ответ
        // ассистента должен упомянуть Ксюшу или ДР — значит контекст понят.
        $replyLc = mb_strtolower($r2->replyText);
        $mentionsBirthday = mb_strpos($replyLc, 'ксюш') !== false
            || mb_strpos($replyLc, 'рожден') !== false
            || mb_strpos($replyLc, 'др') !== false
            || mb_strpos($replyLc, 'мая') !== false;
        if (!$mentionsBirthday) {
            return ScenarioResult::fail(
                'assistant-reply-context',
                0,
                'reply на дату не связал с ДР Ксюши. reply=' . mb_substr($r2->replyText, 0, 200),
            );
        }

        return ScenarioResult::pass('assistant-reply-context', 0);
    }

    /**
     * TTL истории: после clear() (имитация истечения 30-минутного Redis TTL)
     * ссылка на предыдущую задачу не должна находить контекст. Реальный
     * Redis TTL через FrozenClock замокать нельзя, используем прямой clear().
     */
    private function assistantHistoryTtl(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $this->runAssistant($user, 'Создай задачу купить хлеб');
        // Имитация истечения TTL
        $this->historyStore->clear($user);

        // Ассистент не должен найти контекст — при этом задача уже в БД,
        // но он не будет знать о чём «она». Разумное поведение — либо
        // создать новую задачу из «отложи её», либо попросить уточнить.
        $r = $this->runAssistant($user, 'Отложи её на завтра 14:00');

        // Исходная задача «Купить хлеб» должна остаться активной (PENDING)
        // — не отложиться, потому что ассистент без контекста не должен
        // был её тронуть (или хотя бы должен был уточнить, но НЕ молча
        // отложить).
        $active = $this->userTasks($user, [TaskStatus::PENDING, TaskStatus::IN_PROGRESS]);
        $originalActive = false;
        foreach ($active as $t) {
            if (mb_stripos($t->getTitle(), 'хлеб') !== false) {
                $originalActive = true;
                break;
            }
        }
        if (!$originalActive) {
            return ScenarioResult::fail(
                'assistant-history-ttl',
                0,
                'ассистент отложил задачу несмотря на истёкшую историю. reply=' . mb_substr($r->replyText, 0, 180),
            );
        }

        return ScenarioResult::pass('assistant-history-ttl', 0);
    }

    /**
     * /reset-команда: после нескольких сообщений вызов clear() должен
     * занулить историю. Следующее сообщение не должно иметь контекста.
     */
    private function assistantResetCommand(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $this->runAssistant($user, 'Создай задачу постирать');
        $this->runAssistant($user, 'Создай задачу пропылесосить');
        $sizeBefore = $this->historyStore->getSize($user);
        if ($sizeBefore < 2) {
            return ScenarioResult::fail(
                'assistant-reset-command',
                0,
                "ожидалось >=2 сообщений в истории, получено {$sizeBefore}",
            );
        }

        // Имитация /reset — напрямую через store, как делает ResetHandler.
        $this->historyStore->clear($user);

        $sizeAfter = $this->historyStore->getSize($user);
        if ($sizeAfter !== 0) {
            return ScenarioResult::fail(
                'assistant-reset-command',
                0,
                "после clear() ожидалось 0, получено {$sizeAfter}",
            );
        }

        return ScenarioResult::pass('assistant-reset-command', 0);
    }

    /**
     * Множественное создание задач: tool create_task должен вернуть
     * PENDING_CONFIRMATION, ассистент — вставить маркер [CONFIRM:<id>].
     * До подтверждения — задачи в БД нет. После симуляции «yes» — есть.
     */
    private function assistantConfirmCreateBatch(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $r = $this->runAssistant($user, 'Создай 3 задачи: купить хлеб, позвонить маме, забрать посылку');

        if (preg_match('/\[CONFIRM:([a-f0-9]{8})\]/', $r->replyText, $m) !== 1) {
            return ScenarioResult::fail(
                'assistant-confirm-create-batch',
                0,
                'нет маркера [CONFIRM:...] в reply: ' . mb_substr($r->replyText, 0, 200),
            );
        }
        $confirmId = $m[1];

        // В БД пока ничего не создано
        $tasksBefore = $this->userTasks($user);
        if (count($tasksBefore) > 0) {
            return ScenarioResult::fail(
                'assistant-confirm-create-batch',
                0,
                'до подтверждения уже создано задач: ' . count($tasksBefore),
            );
        }

        // Pending в Redis
        $action = $this->pendingStore->get($confirmId);
        if ($action === null || $action->actionType !== 'create_tasks_batch') {
            return ScenarioResult::fail(
                'assistant-confirm-create-batch',
                0,
                'pending action не найден или неверного типа',
            );
        }

        // Симулируем нажатие «Подтверждаю» (consume + execute через executor).
        $consumed = $this->pendingStore->consume($confirmId);
        $resultText = $this->confirmExecutor->execute($user, $consumed);

        $tasksAfter = $this->userTasks($user);
        if (count($tasksAfter) < 2) {
            return ScenarioResult::fail(
                'assistant-confirm-create-batch',
                0,
                "после подтверждения создано {$tasksBefore} задач (ожидалось 2-3). result: " . mb_substr($resultText, 0, 100),
            );
        }

        return ScenarioResult::pass('assistant-confirm-create-batch', 0);
    }

    /**
     * Отмена при cancel_task: пользователь нажал «Отмена», задача
     * остаётся PENDING (не отменена).
     */
    private function assistantConfirmCancelNo(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $em = $this->doctrine->getManager();
        $task = new Task($user, 'Помыть машину');
        $em->persist($task);
        $em->flush();
        $taskId = $task->getId();

        $r = $this->runAssistant($user, 'Отмени задачу помыть машину');

        if (preg_match('/\[CONFIRM:([a-f0-9]{8})\]/', $r->replyText, $m) !== 1) {
            return ScenarioResult::fail(
                'assistant-confirm-cancel-no',
                0,
                'нет маркера [CONFIRM:...] в reply: ' . mb_substr($r->replyText, 0, 200),
            );
        }
        $confirmId = $m[1];

        // Симулируем «Отмена» — просто consume без execute.
        $this->pendingStore->consume($confirmId);

        $em->refresh($task);
        if ($task->getStatus() === TaskStatus::CANCELLED) {
            return ScenarioResult::fail(
                'assistant-confirm-cancel-no',
                0,
                'задача отменена несмотря на "no"',
            );
        }

        return ScenarioResult::pass('assistant-confirm-cancel-no', 0);
    }

    /**
     * Истёкший pending: после consume() (имитация TTL) повторный
     * consume должен вернуть null. Executor вызвать нельзя.
     */
    private function assistantConfirmTtlExpired(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        // Создаём pending руками, не через ассистента (детерминированно)
        $action = new PendingAction(
            userId: $user->getId()->toRfc4122(),
            actionType: 'cancel_task',
            description: 'тест',
            payload: ['task_id' => 'fake'],
            createdAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $confirmId = $this->pendingStore->create($user, $action);

        // Имитация TTL — просто удаляем
        $this->pendingStore->consume($confirmId);

        // Повторный consume → null
        $second = $this->pendingStore->consume($confirmId);
        if ($second !== null) {
            return ScenarioResult::fail(
                'assistant-confirm-ttl-expired',
                0,
                'повторный consume вернул action вместо null',
            );
        }

        return ScenarioResult::pass('assistant-confirm-ttl-expired', 0);
    }

    /**
     * Регресс: одиночное создание задачи НЕ должно требовать подтверждения.
     */
    private function assistantNoConfirmForSingle(): ScenarioResult
    {
        $user = $this->setupFreshAssistant();

        $r = $this->runAssistant($user, 'Создай задачу купить молоко');

        if (preg_match('/\[CONFIRM:([a-f0-9]{8})\]/', $r->replyText) === 1) {
            return ScenarioResult::fail(
                'assistant-no-confirm-for-single',
                0,
                'одиночная задача неожиданно потребовала подтверждение',
            );
        }
        $tasks = $this->userTasks($user);
        if (count($tasks) !== 1) {
            return ScenarioResult::fail(
                'assistant-no-confirm-for-single',
                0,
                'ожидалась 1 задача, найдено ' . count($tasks),
            );
        }

        return ScenarioResult::pass('assistant-no-confirm-for-single', 0);
    }

    /**
     * Создаёт юзера для whitelist-сценариев (с уникальным tg_id),
     * удаляя предыдущего с таким же tg_id если остался от прошлого
     * прогона. SmokeHarness::reset() чистит только тестового юзера
     * (999999999), не наших пользователей сценариев.
     */
    private function ensureCleanWhitelistUser(string $tgId, string $name): User
    {
        $em = $this->doctrine->getManager();
        $existing = $em->getRepository(User::class)->findOneBy(['telegramId' => $tgId]);
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
        }
        $u = new User();
        $u->setTelegramId($tgId);
        $u->setName($name);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /**
     * Незваный пользователь (isAllowed=false, не админ) — gate не пускает.
     */
    private function whitelistBlocksUnknown(): ScenarioResult
    {
        $stranger = $this->ensureCleanWhitelistUser('1234567890', 'Stranger');

        if ($this->accessGate->isAllowed($stranger)) {
            return ScenarioResult::fail('whitelist-blocks-unknown-user', 0, 'AccessGate пустил незваного');
        }

        return ScenarioResult::pass('whitelist-blocks-unknown-user', 0);
    }

    /**
     * После setAllowed(true) — gate пускает (имитация approve админом).
     */
    private function whitelistAllowsAfterApprove(): ScenarioResult
    {
        $u = $this->ensureCleanWhitelistUser('1234567891', 'Pending');
        $em = $this->doctrine->getManager();

        if ($this->accessGate->isAllowed($u)) {
            return ScenarioResult::fail('whitelist-allows-after-approve', 0, 'gate пустил до approve');
        }

        $u->setAllowed(true);
        $em->flush();

        if (!$this->accessGate->isAllowed($u)) {
            return ScenarioResult::fail('whitelist-allows-after-approve', 0, 'gate не пропустил после approve');
        }

        return ScenarioResult::pass('whitelist-allows-after-approve', 0);
    }

    /**
     * Имитация /admin invite — создаём User с isAllowed=true,
     * проверяем что AccessGate пускает.
     */
    private function adminInviteCreatesAllowed(): ScenarioResult
    {
        $invited = $this->ensureCleanWhitelistUser('1234567892', 'Invited');
        $invited->setAllowed(true);
        $this->doctrine->getManager()->flush();

        if (!$this->accessGate->isAllowed($invited)) {
            return ScenarioResult::fail('admin-invite-creates-allowed-user', 0, 'gate не пустил приглашённого');
        }

        return ScenarioResult::pass('admin-invite-creates-allowed-user', 0);
    }

    /**
     * Cooldown после rejection: <30 дней назад rejected → canRequestAccess=false.
     * 31 день — снова можно.
     */
    private function whitelistRejectedCooldown(): ScenarioResult
    {
        $u = $this->ensureCleanWhitelistUser('1234567893', 'Rejected');

        $now = new \DateTimeImmutable('2026-04-28 12:00:00 UTC');

        // Только что отклонён → нельзя.
        $u->setRequestRejectedAt($now->modify('-1 day'));
        if ($this->accessGate->canRequestAccess($u, $now)) {
            return ScenarioResult::fail('whitelist-rejected-cooldown', 0, '1 day after reject — should be blocked');
        }

        // 31 день назад → можно.
        $u->setRequestRejectedAt($now->modify('-31 days'));
        if (!$this->accessGate->canRequestAccess($u, $now)) {
            return ScenarioResult::fail('whitelist-rejected-cooldown', 0, '31 days after reject — should be allowed again');
        }

        return ScenarioResult::pass('whitelist-rejected-cooldown', 0);
    }

    private function setupFreshAssistant(): User
    {
        $this->harness->reset();
        $this->harness->notifier()->clear();
        $user = $this->harness->ensureTestUser();
        // реалистичный now, не quiet hours
        $this->harness->freezeTimeAt(new \DateTimeImmutable('2026-06-15 12:00:00 UTC'));
        $this->historyStore->clear($user);
        $this->pendingStore->clear($user);
        $this->msgIdCounter = 1000;

        return $user;
    }

    /**
     * Имитирует AssistantHandler: вызывает Assistant::handle и сохраняет
     * оба сообщения (user + assistant) в историю — чтобы следующий
     * runAssistant() увидел контекст. Возвращает AssistantResult + msg_id
     * ассистент-сообщения (для последующих Reply-сценариев).
     */
    private function runAssistant(User $user, string $message, ?int $replyToMsgId = null): \App\AI\DTO\AssistantResult
    {
        $now = $this->harness->clock()?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $userMsgId = ++$this->msgIdCounter;

        $result = $this->assistant->handle($user, $message, $now, $replyToMsgId);

        $this->historyStore->append($user, new HistoryMessage(
            role: 'user',
            text: $message,
            telegramMsgId: $userMsgId,
            at: $now,
            replyToMsgId: $replyToMsgId,
        ));

        $botMsgId = ++$this->msgIdCounter;
        $this->historyStore->append($user, new HistoryMessage(
            role: 'assistant',
            text: $result->replyText,
            telegramMsgId: $botMsgId,
            at: $now,
            toolsCalled: $result->toolsCalled,
        ));
        // Отдаём botMsgId через побочный канал — полезно для Reply-сценариев.
        $this->lastAssistantMsgId = $botMsgId;

        return $result;
    }

    private int $lastAssistantMsgId = 0;

    /**
     * @param TaskStatus[]|null $statuses
     * @return Task[]
     */
    private function userTasks(User $user, ?array $statuses = null): array
    {
        $statuses ??= [TaskStatus::PENDING, TaskStatus::IN_PROGRESS];
        $qb = $this->doctrine->getManager()
            ->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->andWhere('t.user = :u')
            ->andWhere('t.status IN (:s)')
            ->setParameter('u', $user)
            ->setParameter('s', $statuses);

        return $qb->getQuery()->getResult();
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
        if (isset($opts['singleReminderAt'])) {
            $task->setSingleReminderAt($opts['singleReminderAt']);
        }
        if (array_key_exists('singleReminderRespectQuietHours', $opts)) {
            $task->setSingleReminderRespectQuietHours((bool) $opts['singleReminderRespectQuietHours']);
        }
        if (array_key_exists('respectQuietHours', $opts)) {
            $task->setRespectQuietHours((bool) $opts['respectQuietHours']);
        }

        $em = $this->doctrine->getManager();
        $em->persist($task);
        $em->flush();

        // createdAt — нужно задавать после persist (PrePersist уже сработал).
        // TimestampableTrait::initTimestamps использует `new \DateTimeImmutable('now')`
        // без Clock abstraction, поэтому в smoke-тестах с FrozenClock задача
        // получает РЕАЛЬНОЕ сегодняшнее время. Перезаписываем на frozen-now
        // по умолчанию, чтобы логика вроде «at - createdAt < 10 мин» работала
        // относительно замороженного момента, а не абсолютного.
        $desiredCreatedAt = $opts['createdAt'] ?? $this->harness->clock()?->now();
        if ($desiredCreatedAt !== null) {
            $refl = new \ReflectionProperty(Task::class, 'createdAt');
            $refl->setValue($task, $desiredCreatedAt);
            $em->flush();
        }

        return $task;
    }
}
