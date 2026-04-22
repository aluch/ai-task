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
    /** @var TaskStatus[] */
    public const ACTIVE_STATUSES = [TaskStatus::PENDING, TaskStatus::IN_PROGRESS];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
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
     * Разбуживание SNOOZED происходит строго через Scheduler
     * (`CheckSnoozeWakeupsHandler`). Здесь никакой lazy-реактивации —
     * пользователь получит уведомление от бота, прежде чем задача
     * появится в списках.
     *
     * @return Task[]
     */
    public function findForUser(User $user, ?array $statuses = null, int $limit = 20): array
    {
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
     * Задачи, у которых пора отправить напоминание о приближающемся дедлайне.
     * SQL-часть — простые фильтры:
     *   deadline IS NOT NULL
     *   remind_before_deadline_minutes IS NOT NULL
     *   deadline_reminder_sent_at IS NULL
     *   status ∈ (pending, in_progress)
     *
     * Проверку `deadline - remind_before <= now` делаем в PHP через
     * `Task::shouldRemindBeforeDeadline($now)` — DQL не умеет смешивать
     * TIMESTAMPTZ + INT * INTERVAL без нативного SQL, а таких задач
     * немного (только high/urgent с дедлайном), поэтому PHP-фильтрация
     * дешевле написания нативного запроса.
     *
     * Кандидатов на «дедлайн уже в прошлом, но не послали» не
     * исключаем — ReminderSender форматирует «уже просрочено на N мин».
     *
     * @return Task[]
     */
    public function findDeadlineReminderCandidates(\DateTimeImmutable $now): array
    {
        $eligible = $this->createQueryBuilder('t')
            ->andWhere('t.deadline IS NOT NULL')
            ->andWhere('t.remindBeforeDeadlineMinutes IS NOT NULL')
            ->andWhere('t.deadlineReminderSentAt IS NULL')
            ->andWhere('t.status IN (:open)')
            ->setParameter('open', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS])
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $eligible,
            fn (Task $t) => $t->shouldRemindBeforeDeadline($now),
        ));
    }

    /**
     * Задачи, у которых пора отправить периодическое напоминание.
     * Условие кандидата:
     *   reminder_interval_minutes IS NOT NULL
     *   status IN (pending, in_progress) — SNOOZED сознательно исключены,
     *     они сначала должны пройти через Тип В (разбуживание с нотификацией)
     *   last_reminded_at IS NULL AND created_at + 60min <= now
     *     → первое напоминание не раньше чем через час после создания,
     *       чтобы не спамить сразу после создания задачи
     *   OR last_reminded_at + reminder_interval_minutes * interval <= now
     *
     * IN_PROGRESS → эффективный интервал x2. Проверка в PHP (DQL не любит
     * условную арифметику по статусу).
     *
     * Временную арифметику делаем в PHP, не в DQL, по тем же причинам что
     * в findDeadlineReminderCandidates: DATETIME + INT*INTERVAL в DQL
     * через DATE_ADD ведёт себя странно с TIMESTAMPTZ, а кандидатов мало.
     *
     * @return Task[]
     */
    public function findPeriodicReminderCandidates(\DateTimeImmutable $now): array
    {
        $eligible = $this->createQueryBuilder('t')
            ->andWhere('t.reminderIntervalMinutes IS NOT NULL')
            ->andWhere('t.status IN (:open)')
            ->setParameter('open', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS])
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $eligible,
            fn (Task $t) => $this->shouldSendPeriodicReminder($t, $now),
        ));
    }

    private function shouldSendPeriodicReminder(Task $task, \DateTimeImmutable $now): bool
    {
        $interval = $task->getReminderIntervalMinutes();
        if ($interval === null) {
            return false;
        }

        // Для in_progress увеличиваем эффективный интервал вдвое — задача
        // уже в работе, надоедать реже.
        if ($task->getStatus() === TaskStatus::IN_PROGRESS) {
            $interval *= 2;
        }

        $last = $task->getLastRemindedAt();
        if ($last === null) {
            // Ещё ни разу не напоминали. Даём час на «осесть» после создания
            // (иначе напомним ровно в момент создания — некрасиво).
            $firstAllowed = $task->getCreatedAt()->modify('+60 minutes');

            return $now >= $firstAllowed;
        }

        $nextAllowed = $last->modify("+{$interval} minutes");

        return $now >= $nextAllowed;
    }

    /**
     * Кандидаты на одноразовое напоминание (Тип Г): singleReminderAt <= now,
     * ещё не отправлено, в активных статусах.
     *
     * @return Task[]
     */
    public function findSingleReminderCandidates(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.singleReminderAt IS NOT NULL')
            ->andWhere('t.singleReminderSentAt IS NULL')
            ->andWhere('t.singleReminderAt <= :now')
            ->andWhere('t.status IN (:open)')
            ->setParameter('now', $now)
            ->setParameter('open', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS])
            ->getQuery()
            ->getResult();
    }

    /**
     * Отложенные задачи, которым пора проснуться: snoozedUntil <= now.
     * Используется CheckSnoozeWakeupsHandler'ом для активной реактивации
     * с уведомлением пользователя (вместо прежнего lazy-пробуждения).
     *
     * @return Task[]
     */
    public function findSnoozeWakeupCandidates(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.status = :snoozed')
            ->andWhere('t.snoozedUntil IS NOT NULL')
            ->andWhere('t.snoozedUntil <= :now')
            ->setParameter('snoozed', TaskStatus::SNOOZED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
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
