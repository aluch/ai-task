<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\PendingAction;
use App\Entity\Task;
use App\Entity\TaskContext;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskSource;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Исполняет подтверждённые PendingAction. Вынесен из handler'а,
 * чтобы один и тот же код мог запускаться и из callback (нажатие
 * кнопки), и из текстового «да/подтверждаю».
 *
 * Возвращает строку результата для показа пользователю — либо успех
 * («✅ Создано N задач»), либо ошибку. Предполагается, что вызывающий
 * уже сделал consume(), и action валиден (не null).
 */
class ConfirmationExecutor
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskParser $taskParser,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(User $user, PendingAction $action): string
    {
        return match ($action->actionType) {
            'create_tasks_batch' => $this->executeCreateBatch($user, $action),
            'cancel_task' => $this->executeCancelTask($user, $action),
            'bulk_mark_done' => $this->executeBulkMarkDone($user, $action),
            'bulk_snooze' => $this->executeBulkSnooze($user, $action),
            'bulk_set_priority' => $this->executeBulkSetPriority($user, $action),
            'bulk_cancel' => $this->executeBulkCancel($user, $action),
            default => "❌ Неизвестный тип действия: {$action->actionType}",
        };
    }

    /**
     * payload: {raw_texts: ["...", "..."]}
     */
    private function executeCreateBatch(User $user, PendingAction $action): string
    {
        $rawTexts = (array) ($action->payload['raw_texts'] ?? []);
        if ($rawTexts === []) {
            return '❌ Список задач пуст.';
        }

        $em = $this->doctrine->getManager();
        $contextRepo = $em->getRepository(TaskContext::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $createdTitles = [];

        foreach ($rawTexts as $rawText) {
            if (!is_string($rawText) || trim($rawText) === '') {
                continue;
            }
            $dto = $this->taskParser->parse($rawText, $user, $now);
            $task = new Task($user, $dto->title);
            if ($dto->description !== null) {
                $task->setDescription($dto->description);
            }
            if ($dto->deadline !== null) {
                $task->setDeadline($dto->deadline);
            }
            if ($dto->estimatedMinutes !== null) {
                $task->setEstimatedMinutes($dto->estimatedMinutes);
            }
            $task->setPriority($dto->priority);
            if ($dto->remindBeforeDeadlineMinutes !== null) {
                $task->setRemindBeforeDeadlineMinutes($dto->remindBeforeDeadlineMinutes);
            }
            if ($dto->reminderIntervalMinutes !== null) {
                $task->setReminderIntervalMinutes($dto->reminderIntervalMinutes);
            }
            $task->setSource(TaskSource::AI_PARSED);
            if ($dto->contextCodes !== []) {
                $found = $contextRepo->createQueryBuilder('c')
                    ->andWhere('c.code IN (:codes)')
                    ->setParameter('codes', $dto->contextCodes)
                    ->getQuery()
                    ->getResult();
                foreach ($found as $ctx) {
                    $task->addContext($ctx);
                }
            }
            $em->persist($task);
            $createdTitles[] = $task->getTitle();
        }
        $em->flush();

        $this->logger->info('Confirmed: create_tasks_batch', [
            'user_id' => $user->getId()->toRfc4122(),
            'count' => count($createdTitles),
        ]);

        $list = '';
        foreach ($createdTitles as $i => $title) {
            $list .= "\n" . ($i + 1) . '. ' . $title;
        }

        return '✅ Создано задач: ' . count($createdTitles) . $list;
    }

    /**
     * payload: {task_id: "uuid"}
     */
    private function executeCancelTask(User $user, PendingAction $action): string
    {
        $taskId = (string) ($action->payload['task_id'] ?? '');
        $task = $this->loadOwnedTask($user, $taskId);
        if ($task === null) {
            return '❌ Задача не найдена (возможно, удалена).';
        }
        $task->cancel();
        $this->doctrine->getManager()->flush();

        $this->logger->info('Confirmed: cancel_task', [
            'task_id' => $taskId,
        ]);

        return "❌ Задача отменена: «{$task->getTitle()}»";
    }

    /**
     * payload: {task_ids: ["uuid", "uuid"]}
     */
    private function executeBulkMarkDone(User $user, PendingAction $action): string
    {
        $tasks = $this->loadOwnedTasks($user, (array) ($action->payload['task_ids'] ?? []));
        if ($tasks === []) {
            return '❌ Ни одной задачи не нашёл.';
        }
        foreach ($tasks as $t) {
            $t->markDone();
        }
        $this->doctrine->getManager()->flush();

        $this->logger->info('Confirmed: bulk_mark_done', ['count' => count($tasks)]);

        return '✅ Помечено выполненными: ' . count($tasks) . $this->formatTitles($tasks);
    }

    /**
     * payload: {task_ids: [...], until_iso: "..."}
     */
    private function executeBulkSnooze(User $user, PendingAction $action): string
    {
        $tasks = $this->loadOwnedTasks($user, (array) ($action->payload['task_ids'] ?? []));
        $untilIso = (string) ($action->payload['until_iso'] ?? '');
        if ($tasks === [] || $untilIso === '') {
            return '❌ Не хватает данных для bulk-snooze.';
        }
        try {
            $until = (new \DateTimeImmutable($untilIso))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return '❌ Невалидное время: ' . $e->getMessage();
        }

        foreach ($tasks as $t) {
            $t->snooze($until);
            $t->setRespectQuietHours(false);
        }
        $this->doctrine->getManager()->flush();

        $this->logger->info('Confirmed: bulk_snooze', ['count' => count($tasks)]);

        $userTz = new \DateTimeZone($user->getTimezone());
        $localUntil = $until->setTimezone($userTz)->format('Y-m-d H:i');

        return '⏸ Отложено: ' . count($tasks) . ' до ' . $localUntil . $this->formatTitles($tasks);
    }

    /**
     * payload: {task_ids: [...], priority: "high"}
     */
    private function executeBulkSetPriority(User $user, PendingAction $action): string
    {
        $tasks = $this->loadOwnedTasks($user, (array) ($action->payload['task_ids'] ?? []));
        $priority = TaskPriority::tryFrom((string) ($action->payload['priority'] ?? ''));
        if ($tasks === [] || $priority === null) {
            return '❌ Не хватает данных для bulk-set-priority.';
        }
        foreach ($tasks as $t) {
            $t->setPriority($priority);
        }
        $this->doctrine->getManager()->flush();

        $this->logger->info('Confirmed: bulk_set_priority', [
            'count' => count($tasks),
            'priority' => $priority->value,
        ]);

        return '🎯 Приоритет ' . $priority->value . ' выставлен у ' . count($tasks) . ' задач' . $this->formatTitles($tasks);
    }

    /**
     * payload: {task_ids: [...]}
     */
    private function executeBulkCancel(User $user, PendingAction $action): string
    {
        $tasks = $this->loadOwnedTasks($user, (array) ($action->payload['task_ids'] ?? []));
        if ($tasks === []) {
            return '❌ Ни одной задачи не нашёл.';
        }
        foreach ($tasks as $t) {
            $t->cancel();
        }
        $this->doctrine->getManager()->flush();

        $this->logger->info('Confirmed: bulk_cancel', ['count' => count($tasks)]);

        return '❌ Отменено задач: ' . count($tasks) . $this->formatTitles($tasks);
    }

    private function loadOwnedTask(User $user, string $taskId): ?Task
    {
        if ($taskId === '' || !Uuid::isValid($taskId)) {
            return null;
        }
        $em = $this->doctrine->getManager();
        $task = $em->getRepository(Task::class)->find(Uuid::fromString($taskId));
        if ($task === null || !$task->getUser()->getId()->equals($user->getId())) {
            return null;
        }

        return $task;
    }

    /**
     * @param string[] $taskIds
     * @return Task[]
     */
    private function loadOwnedTasks(User $user, array $taskIds): array
    {
        $result = [];
        foreach ($taskIds as $id) {
            if (!is_string($id)) {
                continue;
            }
            $task = $this->loadOwnedTask($user, $id);
            if ($task !== null) {
                $result[] = $task;
            }
        }

        return $result;
    }

    /**
     * @param Task[] $tasks
     */
    private function formatTitles(array $tasks): string
    {
        $list = '';
        foreach ($tasks as $i => $t) {
            $list .= "\n" . ($i + 1) . '. ' . $t->getTitle();
        }

        return $list;
    }
}
