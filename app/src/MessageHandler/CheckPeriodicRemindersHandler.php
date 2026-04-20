<?php

declare(strict_types=1);

namespace App\MessageHandler;

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
    ) {
    }

    public function __invoke(CheckPeriodicRemindersMessage $message): void
    {
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Task::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

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
