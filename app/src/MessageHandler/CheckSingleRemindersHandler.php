<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Clock\Clock;
use App\Entity\Task;
use App\Message\CheckSingleRemindersMessage;
use App\Notification\ReminderSender;
use App\Service\HeartbeatTracker;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Одноразовые напоминания на точный момент (Тип Г). Выбирает задачи с
 * singleReminderAt <= now и ещё не отправленным singleReminderSentAt,
 * в активных статусах. Передаёт `ReminderSender::sendSingleReminder`,
 * который отправляет уведомление и помечает sent_at.
 */
#[AsMessageHandler]
final class CheckSingleRemindersHandler
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReminderSender $sender,
        private readonly LoggerInterface $logger,
        private readonly HeartbeatTracker $heartbeat,
        private Clock $clock,
    ) {
    }

    public function __invoke(CheckSingleRemindersMessage $message): void
    {
        // Heartbeat — см. CheckDeadlineRemindersHandler.
        $this->heartbeat->recordTick($this->clock->now());

        try {
            $this->tick();
        } catch (\Throwable $e) {
            $this->logger->critical('Single reminder handler failed', [
                'handler' => self::class,
                'now' => $this->clock->now()->format('c'),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    private function tick(): void
    {
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Task::class);
        $now = $this->clock->now();

        $candidates = $repo->findSingleReminderCandidates($now);

        if ($candidates === []) {
            return;
        }

        $this->logger->info('Single reminder tick', [
            'candidates' => count($candidates),
        ]);

        foreach ($candidates as $task) {
            $result = $this->sender->sendSingleReminder($task);
            $this->logger->info('Single reminder result', [
                'task_id' => $task->getId()->toRfc4122(),
                'result' => $result->value,
            ]);
        }
    }
}
