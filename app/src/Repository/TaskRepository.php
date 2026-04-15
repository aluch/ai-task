<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * @return Task[]
     */
    public function findForUser(User $user, ?TaskStatus $status = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $status);
        } else {
            // Без явного фильтра скрываем активно-отложенные задачи.
            $qb->andWhere('t.status != :snoozed OR t.snoozedUntil IS NULL OR t.snoozedUntil <= :now')
                ->setParameter('snoozed', TaskStatus::SNOOZED)
                ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }

        return $qb->getQuery()->getResult();
    }
}
