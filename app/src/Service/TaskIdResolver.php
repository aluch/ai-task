<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Entity\User;
use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Унифицированный резолвер task_id для команд бота.
 *
 * Стратегия:
 * - Полный UUID (36 символов) → прямой поиск через Uuid::fromString
 * - 8-35 символов → префиксный поиск. Если нашли >1 — ошибка «неоднозначный»
 * - <8 символов или некорректный формат → ошибка «нужен полный UUID»
 *
 * UUID v7 сортируем по времени с разрешением мс, 8 hex-символов дают
 * разрешение ~16 секунд → короткий префикс может давать коллизии у задач,
 * созданных близко по времени. Резолвер всегда проверяет уникальность.
 */
class TaskIdResolver
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {
    }

    public function resolve(string $input, User $user): Task
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw new TaskIdException('ID задачи не указан.');
        }

        if (mb_strlen($trimmed) < 8) {
            throw new TaskIdException('ID слишком короткий — используй полный UUID или команду без аргументов.');
        }

        // Полный UUID → прямой lookup
        if (Uuid::isValid($trimmed)) {
            $task = $this->tasks->find(Uuid::fromString($trimmed));
            if ($task === null || $task->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
                throw new TaskIdException("Задача не найдена.");
            }

            return $task;
        }

        // Префикс → префиксный lookup с проверкой уникальности
        $matches = $this->findByPrefix($trimmed, $user);

        if ($matches === []) {
            throw new TaskIdException("Задача не найдена.");
        }

        if (count($matches) > 1) {
            throw new TaskIdException('Неоднозначный префикс, используй полный UUID.');
        }

        return $matches[0];
    }

    /**
     * @return Task[]
     */
    private function findByPrefix(string $prefix, User $user): array
    {
        $all = $this->tasks->findBy(['user' => $user]);

        return array_values(array_filter(
            $all,
            fn (Task $t) => str_starts_with($t->getId()->toRfc4122(), $prefix),
        ));
    }
}
