<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use App\Service\SnoozeReactivator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    /** @var TaskStatus[] */
    public const ACTIVE_STATUSES = [TaskStatus::PENDING, TaskStatus::IN_PROGRESS];

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
     * Задачи пользователя с фильтрацией по статусам.
     *
     * @param TaskStatus[]|null $statuses
     *   - null (по умолчанию) → только PENDING и IN_PROGRESS (активные).
     *     Это то, что пользователь реально может делать — DONE и CANCELLED
     *     скрыты, SNOOZED тоже скрыты через статус-фильтр.
     *   - [] (пустой массив) → все статусы без фильтра.
     *   - Конкретный массив → только перечисленные статусы.
     *
     * Всегда вызывает SnoozeReactivator::reactivateExpired() перед выборкой,
     * чтобы истёкшие SNOOZED стали PENDING и попали в «активные».
     *
     * @return Task[]
     */
    public function findForUser(User $user, ?array $statuses = null, int $limit = 20): array
    {
        $this->reactivator?->reactivateExpired($user);

        if ($statuses === null) {
            $statuses = self::ACTIVE_STATUSES;
        }

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($statuses !== []) {
            $qb->andWhere('t.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Задачи без активных блокеров (или без блокеров вообще).
     * Фильтр по статусам — как у findForUser (дефолт: активные).
     * TODO: для масштабирования заменить PHP-фильтрацию на SQL subquery.
     *
     * @param TaskStatus[]|null $statuses
     * @return Task[]
     */
    public function findUnblockedForUser(User $user, ?array $statuses = null): array
    {
        $all = $this->findForUser($user, $statuses);

        return array_values(array_filter($all, fn (Task $t) => !$t->isBlocked()));
    }
}
