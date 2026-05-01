<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Clock\Clock;
use App\Entity\Task;
use App\Message\CheckDeadlineRemindersMessage;
use App\Notification\ReminderSender;
use App\Service\HeartbeatTracker;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckDeadlineRemindersHandler
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReminderSender $sender,
        private readonly LoggerInterface $logger,
        private readonly HeartbeatTracker $heartbeat,
        private Clock $clock,
    ) {
    }

    public function __invoke(CheckDeadlineRemindersMessage $message): void
    {
        // Heartbeat ДО try: если tick упадёт — Messenger ретрайнет, но
        // запись «scheduler жив» уже есть, /health не покажет stale из-за
        // одного transient сбоя. Если worker совсем умер — heartbeat не
        // пишется и /health через 3 минуты покажет stale (корректно).
        $this->heartbeat->recordTick($this->clock->now());

        try {
            $this->tick();
        } catch (\Throwable $e) {
            // Messenger поймает и залогирует тоже — но наш critical содержит
            // имя handler'а и now в UTC, проще найти в логах по паттерну.
            $this->logger->critical('Deadline reminder handler failed', [
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

        $candidates = $repo->findDeadlineReminderCandidates($now);

        if ($candidates === []) {
            return;
        }

        $this->logger->info('Deadline reminder tick', [
            'candidates' => count($candidates),
        ]);

        foreach ($candidates as $task) {
            $result = $this->sender->sendDeadlineReminder($task);
            $this->logger->info('Deadline reminder result', [
                'task_id' => $task->getId()->toRfc4122(),
                'result' => $result->value,
            ]);
        }
    }
}
