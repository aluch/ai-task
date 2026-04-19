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

    /**
     * Пагинируемая выборка с «умной» сортировкой: urgent → high → medium → low,
     * внутри приоритета — по дедлайну ASC (nulls last), внутри даты — по
     * createdAt ASC. Это порядок «что сделать первым» с точки зрения юзера.
     *
     * Поле `search` — часть названия задачи (ILIKE %root% по корню, стемминг
     * делается в вызывающем коде). Пустая строка = без фильтра.
     *
     * @param TaskStatus[]|null $statuses
     * @return Task[]
     */
    public function findForUserPaginated(
        User $user,
        ?array $statuses = null,
        int $limit = 10,
        int $offset = 0,
        string $search = '',
    ): array {
        $this->reactivator?->reactivateExpired($user);

        $qb = $this->buildPaginationQuery($user, $statuses, $search);

        // Сортировка: приоритет через CASE → дедлайн nulls last → createdAt
        $qb->addSelect('
            CASE t.priority
                WHEN :urgent THEN 1
                WHEN :high THEN 2
                WHEN :medium THEN 3
                WHEN :low THEN 4
                ELSE 5
            END AS HIDDEN priority_order
        ')
            ->setParameter('urgent', \App\Enum\TaskPriority::URGENT)
            ->setParameter('high', \App\Enum\TaskPriority::HIGH)
            ->setParameter('medium', \App\Enum\TaskPriority::MEDIUM)
            ->setParameter('low', \App\Enum\TaskPriority::LOW)
            ->orderBy('priority_order', 'ASC')
            ->addOrderBy('CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('t.deadline', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param TaskStatus[]|null $statuses
     */
    public function countForUser(User $user, ?array $statuses = null, string $search = ''): int
    {
        $this->reactivator?->reactivateExpired($user);

        $qb = $this->buildPaginationQuery($user, $statuses, $search);
        $qb->select('COUNT(t.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Незаблокированные задачи с пагинацией. Сначала PHP-фильтрация по
     * isBlocked (как и в findUnblockedForUser), потом срез. Для масштабирования
     * когда появится много задач — переехать на SQL subquery (NOT EXISTS).
     *
     * @return Task[]
     */
    public function findUnblockedForUserPaginated(
        User $user,
        int $limit = 5,
        int $offset = 0,
        string $search = '',
    ): array {
        $all = $this->findForUserPaginated($user, null, 500, 0, $search);
        $unblocked = array_values(array_filter($all, fn (Task $t) => !$t->isBlocked()));

        return array_slice($unblocked, $offset, $limit);
    }

    public function countUnblockedForUser(User $user, string $search = ''): int
    {
        $all = $this->findForUserPaginated($user, null, 500, 0, $search);

        return count(array_filter($all, fn (Task $t) => !$t->isBlocked()));
    }

    /**
     * @param TaskStatus[]|null $statuses
     */
    private function buildPaginationQuery(User $user, ?array $statuses, string $search)
    {
        if ($statuses === null) {
            $statuses = self::ACTIVE_STATUSES;
        }

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user);

        if ($statuses !== []) {
            $qb->andWhere('t.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        if ($search !== '') {
            $qb->andWhere('LOWER(t.title) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        return $qb;
    }
}
