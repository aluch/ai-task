<?php

declare(strict_types=1);

namespace App\AI\Tool\Support;

use App\AI\Tool\ToolResult;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Общая логика поиска задачи по UUID или fuzzy query, используемая
 * несколькими Assistant-tool'ами. Возвращает либо найденную задачу,
 * либо готовый ToolResult с понятной ошибкой — вызывающий код просто
 * прокидывает его дальше.
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
    ) {
    }

    /**
     * Примитивный морфо-стемминг: отрезаем 2 последних символа если слово >3.
     * «тренировку» → «тренировк» → находит «тренировка», «тренировки».
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

        $root = $this->toSearchRoot($taskQuery);
        $matches = $repo->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('LOWER(t.title) LIKE :q')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('q', '%' . $root . '%')
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getResult();

        if ($matches === []) {
            return ToolResult::error("Не нашёл задачу с «{$taskQuery}» в названии.");
        }
        if (count($matches) > 1) {
            $lines = ["Найдено несколько задач с «{$taskQuery}»:"];
            foreach ($matches as $t) {
                $lines[] = "- [id:{$t->getId()->toRfc4122()}] {$t->getTitle()}";
            }
            $lines[] = 'Уточни какую именно.';

            return ToolResult::error(implode("\n", $lines));
        }

        return $matches[0];
    }
}
