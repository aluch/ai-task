<?php

declare(strict_types=1);

namespace App\AI\Tool\Support;

use App\AI\Exception\ClaudeRateLimitException;
use App\AI\Exception\ClaudeTransientException;
use App\AI\TaskMatcher;
use App\AI\Tool\ToolResult;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Общая логика поиска задачи по UUID или fuzzy query, используемая
 * несколькими Assistant-tool'ами. Возвращает либо найденную задачу,
 * либо готовый ToolResult с понятной ошибкой — вызывающий код просто
 * прокидывает его дальше.
 *
 * Поиск по query выполняется в два шага:
 * 1. Семантический матчер через Haiku (TaskMatcher) — ловит русскую
 *    морфологию («пополнение» ≈ «пополнить», «стрижку» ≈ «стрижка»).
 * 2. Fallback: ILIKE по стемминг-корню — если Claude упал/rate-limit.
 */
final class TaskLookup
{
    public const OPEN_STATUSES = [
        TaskStatus::PENDING,
        TaskStatus::IN_PROGRESS,
        TaskStatus::SNOOZED,
    ];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskMatcher $matcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Примитивный морфо-стемминг для fallback: отрезаем 2 последних
     * символа каждого слова >3. Используется только если TaskMatcher
     * (Haiku) недоступен.
     */
    public function toSearchRoot(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        if (mb_strlen($normalized) > 3) {
            $normalized = mb_substr($normalized, 0, -2);
        }

        return $normalized;
    }

    /**
     * Единая точка разрешения task_id / task_query параметров.
     *
     * @param TaskStatus[]|null $statuses массив допустимых статусов; null = OPEN_STATUSES
     * @return Task|ToolResult  Task если нашли одну — иначе ToolResult::error с причиной
     */
    public function resolve(
        User $user,
        string $taskId,
        string $taskQuery,
        ?array $statuses = null,
    ): Task|ToolResult {
        $statuses ??= self::OPEN_STATUSES;
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Task::class);

        if ($taskId !== '') {
            if (!Uuid::isValid($taskId)) {
                return ToolResult::error('Неверный формат UUID задачи.');
            }
            $task = $repo->find(Uuid::fromString($taskId));
            if ($task === null || !$task->getUser()->getId()->equals($user->getId())) {
                return ToolResult::error('Задача по UUID не найдена.');
            }

            return $task;
        }

        if ($taskQuery === '') {
            return ToolResult::error('Нужен task_id или task_query.');
        }

        $matches = $this->findByQuery($user, $taskQuery, $statuses);

        if ($matches === []) {
            return ToolResult::error("Не нашёл задачу с «{$taskQuery}» в названии.");
        }
        if (count($matches) > 1) {
            $lines = ["Найдено несколько задач с «{$taskQuery}»:"];
            foreach ($matches as $t) {
                $lines[] = "- [id:{$t->getId()->toRfc4122()}] {$t->getTitle()}";
            }
            $lines[] = 'В reply перечисли варианты пользователю — пусть он напишет '
                . 'следующим сообщением конкретнее (например title точнее). Вопросов '
                . 'с ожиданием ответа не задавай — у тебя нет памяти между репликами.';

            return ToolResult::error(implode("\n", $lines));
        }

        return $matches[0];
    }

    /**
     * Семантический поиск задач по пользовательскому запросу. Возвращает
     * 0-N Task'ов в порядке релевантности. Пытается через Haiku; при
     * падении Claude — откатывается на стемминг-ILIKE.
     *
     * @param TaskStatus[]|null $statuses
     * @return Task[]
     */
    public function findByQuery(User $user, string $query, ?array $statuses = null, int $limit = 3): array
    {
        $statuses ??= self::OPEN_STATUSES;

        try {
            return $this->matcher->findByQuery($user, $query, $statuses, $limit);
        } catch (ClaudeRateLimitException | ClaudeTransientException $e) {
            $this->logger->warning('TaskMatcher unavailable, falling back to ILIKE stem', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);
        }

        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Task::class);

        $root = $this->toSearchRoot($query);
        $qb = $repo->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('LOWER(t.title) LIKE :q')
            ->setParameter('user', $user)
            ->setParameter('q', '%' . $root . '%')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($statuses !== []) {
            $qb->andWhere('t.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        return $qb->getQuery()->getResult();
    }
}
