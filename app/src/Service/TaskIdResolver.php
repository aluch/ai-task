<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Entity\User;
use App\Exception\TaskIdException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Унифицированный резолвер task_id для команд бота.
 *
 * Стратегия:
 * - Полный UUID (36 символов) → прямой поиск через Uuid::fromString
 * - 8-35 символов → префиксный поиск. Если нашли >1 — ошибка «неоднозначный»
 * - <8 символов или некорректный формат → ошибка «нужен полный UUID»
 *
 * Использует ManagerRegistry: в долгоживущем bot-процессе после
 * resetManager() прямая ссылка на repository становится stale (найденная
 * сущность попадает в identity map старого EM, flush по новому EM ничего
 * не пишет). getManager() всегда отдаёт живой EM и свежий repository.
 */
class TaskIdResolver
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
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

        $repo = $this->doctrine->getManager()->getRepository(Task::class);

        // Полный UUID → прямой lookup
        if (Uuid::isValid($trimmed)) {
            $task = $repo->find(Uuid::fromString($trimmed));
            if ($task === null || !$task->getUser()->getId()->equals($user->getId())) {
                throw new TaskIdException('Задача не найдена.');
            }

            return $task;
        }

        // Префикс → префиксный lookup с проверкой уникальности
        $all = $repo->findBy(['user' => $user]);
        $matches = array_values(array_filter(
            $all,
            fn (Task $t) => str_starts_with($t->getId()->toRfc4122(), $trimmed),
        ));

        if ($matches === []) {
            throw new TaskIdException('Задача не найдена.');
        }

        if (count($matches) > 1) {
            throw new TaskIdException('Неоднозначный префикс, используй полный UUID.');
        }

        return $matches[0];
    }
}
