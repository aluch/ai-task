<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Clock\Clock;
use App\Entity\Task;
use App\Message\CheckPeriodicRemindersMessage;
use App\Notification\ReminderSender;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckPeriodicRemindersHandler
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReminderSender $sender,
        private readonly LoggerInterface $logger,
        private Clock $clock,
    ) {
    }

    public function __invoke(CheckPeriodicRemindersMessage $message): void
    {
        try {
            $this->tick();
        } catch (\Throwable $e) {
            $this->logger->critical('Periodic reminder handler failed', [
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

        $candidates = $repo->findPeriodicReminderCandidates($now);

        if ($candidates === []) {
            return;
        }

        $this->logger->info('Periodic reminder tick', [
            'candidates' => count($candidates),
        ]);

        foreach ($candidates as $task) {
            $result = $this->sender->sendPeriodicReminder($task);
            $this->logger->info('Periodic reminder result', [
                'task_id' => $task->getId()->toRfc4122(),
                'result' => $result->value,
            ]);
        }
    }
}
