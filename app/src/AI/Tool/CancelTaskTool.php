<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\DTO\PendingAction;
use App\AI\PendingActionStore;
use App\AI\Tool\Support\TaskLookup;
use App\Entity\User;
use Psr\Log\LoggerInterface;

class CancelTaskTool implements AssistantTool
{
    public function __construct(
        private readonly TaskLookup $lookup,
        private readonly PendingActionStore $pendingStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'cancel_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Отменить задачу — пометить как неактуальную (статус CANCELLED). В отличие
        от mark_task_done это для случаев когда задача больше не нужна, не «сделана»
        («передумал», «отменил планы», «больше неактуально»).

        Деструктивная операция — всегда требует подтверждения. Tool возвращает
        PENDING_CONFIRMATION:cancel_task:<id>. В reply сформулируй вопрос
        пользователю и вставь маркер [CONFIRM:<id>] — он превратится в кнопки.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => 'Полный UUID задачи'],
                'task_query' => ['type' => 'string', 'description' => 'Описание задачи для семантического поиска'],
            ],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $taskId = isset($input['task_id']) ? trim((string) $input['task_id']) : '';
        $taskQuery = isset($input['task_query']) ? trim((string) $input['task_query']) : '';
        if ($taskId === '' && $taskQuery === '') {
            return ToolResult::error('Нужен task_id или task_query.');
        }

        $found = $this->lookup->resolve($user, $taskId, $taskQuery);
        if ($found instanceof ToolResult) {
            return $found;
        }
        $task = $found;

        $action = new PendingAction(
            userId: $user->getId()->toRfc4122(),
            actionType: 'cancel_task',
            description: "Отменить задачу: «{$task->getTitle()}»?",
            payload: ['task_id' => $task->getId()->toRfc4122()],
            createdAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $confirmId = $this->pendingStore->create($user, $action);

        $this->logger->info('CancelTaskTool: pending', [
            'task_id' => $task->getId()->toRfc4122(),
            'confirmation_id' => $confirmId,
        ]);

        return ToolResult::ok(
            "PENDING_CONFIRMATION:cancel_task:{$confirmId}\nОтменить задачу: «{$task->getTitle()}»?",
            ['confirmation_id' => $confirmId, 'pending' => true],
        );
    }
}
