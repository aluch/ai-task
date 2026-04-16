<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Exception\CyclicDependencyException;

class DependencyValidator
{
    /**
     * Проверяет, что добавление связи blocked → blocker не создаст цикл.
     * DFS от blocker по его blockedBy — если достигнем blocked, значит цикл.
     */
    public function validateNoCycle(Task $blocked, Task $blocker): void
    {
        if ($blocked->getId()->equals($blocker->getId())) {
            throw new \LogicException('Задача не может блокировать саму себя.');
        }

        $visited = [];
        if ($this->hasCycle($blocker, $blocked, $visited)) {
            throw new CyclicDependencyException(
                'Невозможно: это создаст циклическую зависимость.',
            );
        }
    }

    /**
     * @param array<string, true> $visited
     */
    private function hasCycle(Task $current, Task $target, array &$visited): bool
    {
        $id = $current->getId()->toRfc4122();

        if (isset($visited[$id])) {
            return false;
        }

        $visited[$id] = true;

        foreach ($current->getBlockedBy() as $upstream) {
            if ($upstream->getId()->equals($target->getId())) {
                return true;
            }
            if ($this->hasCycle($upstream, $target, $visited)) {
                return true;
            }
        }

        return false;
    }
}
