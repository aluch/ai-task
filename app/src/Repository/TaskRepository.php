<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use App\Service\SnoozeReactivator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\Attribute\SubscribedService;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    private ?SnoozeReactivator $reactivator = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Setter-инъекция чтобы избежать циклической зависимости:
     * SnoozeReactivator использует EntityManager, который зависит от репозиториев.
     */
    public function setReactivator(SnoozeReactivator $reactivator): void
    {
        $this->reactivator = $reactivator;
    }

    /**
     * @return Task[]
     */
    public function findForUser(User $user, ?TaskStatus $status = null, int $limit = 20): array
    {
        $this->reactivator?->reactivateExpired($user);

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

    /**
     * Задачи без активных блокеров (или без блокеров вообще).
     * TODO: для масштабирования заменить PHP-фильтрацию на SQL subquery.
     *
     * @return Task[]
     */
    public function findUnblockedForUser(User $user, ?TaskStatus $status = null): array
    {
        $all = $this->findForUser($user, $status);

        return array_values(array_filter($all, fn (Task $t) => !$t->isBlocked()));
    }
}
