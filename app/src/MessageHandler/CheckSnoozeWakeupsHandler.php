<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Clock\Clock;
use App\Entity\Task;
use App\Message\CheckSnoozeWakeupsMessage;
use App\Notification\ReminderSender;
use App\Notification\SendResult;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Активное пробуждение SNOOZED-задач: находим те, у которых snoozedUntil
 * наступил, и передаём ReminderSender'у. Sender отвечает и за нотификацию,
 * и за реактивацию — но только после того, как уведомление реально ушло
 * (или задержалось из-за quiet hours; тогда задача остаётся SNOOZED, на
 * следующем тике пробуем снова).
 *
 * Раньше пробуждение шло лениво из TaskRepository при выборках списков
 * (через удалённый SnoozeReactivator). Теперь источник истины — Scheduler:
 * пользователь всегда получает явное уведомление, а не внезапно находит
 * задачу в /list.
 */
#[AsMessageHandler]
final class CheckSnoozeWakeupsHandler
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReminderSender $sender,
        private readonly LoggerInterface $logger,
        private Clock $clock,
    ) {
    }

    public function __invoke(CheckSnoozeWakeupsMessage $message): void
    {
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Task::class);
        $now = $this->clock->now();

        $candidates = $repo->findSnoozeWakeupCandidates($now);

        if ($candidates === []) {
            return;
        }

        $this->logger->info('Snooze wakeup tick', [
            'candidates' => count($candidates),
        ]);

        foreach ($candidates as $task) {
            $result = $this->sender->sendSnoozeWakeup($task);
            $this->logger->info('Snooze wakeup result', [
                'task_id' => $task->getId()->toRfc4122(),
                'result' => $result->value,
            ]);

            // SENT → задача уже реактивирована внутри sender'а.
            // SKIPPED_QUIET_HOURS / FAILED / SKIPPED_NO_CHAT_ID → не реактивируем,
            //   дождёмся следующего тика (иначе разбудим без уведомления —
            //   теряется смысл явного напоминания).
            if ($result !== SendResult::SENT) {
                continue;
            }
        }
    }
}
