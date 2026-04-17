<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Lazy-реактивация отложенных задач: если snoozedUntil наступил, переводит
 * задачу обратно в PENDING. Вызывается перед показом списков.
 *
 * Потом вынесем в Scheduler (Этап 5), чтобы это работало и без запроса
 * пользователя. Интерфейс (метод reactivateExpired) одинаковый — можно будет
 * вызывать из scheduler handler'а без изменений.
 */
class SnoozeReactivator
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Реактивирует задачи пользователя, у которых snoozedUntil истёк.
     * Возвращает количество реактивированных. Если таких нет — в БД
     * ничего не пишет.
     */
    public function reactivateExpired(User $user): int
    {
        $em = $this->doctrine->getManager();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $expired = $em->getRepository(\App\Entity\Task::class)
            ->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.status = :snoozed')
            ->andWhere('t.snoozedUntil IS NOT NULL')
            ->andWhere('t.snoozedUntil <= :now')
            ->setParameter('user', $user)
            ->setParameter('snoozed', TaskStatus::SNOOZED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        if ($expired === []) {
            return 0;
        }

        foreach ($expired as $task) {
            $this->logger->info('Snooze expired, reactivating task', [
                'task_id' => $task->getId()->toRfc4122(),
                'title' => $task->getTitle(),
                'snoozed_until' => $task->getSnoozedUntil()?->format('c'),
            ]);
            $task->reactivate();
        }

        $em->flush();

        return count($expired);
    }
}
