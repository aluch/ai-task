<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\DTO\PendingAction;
use App\AI\PendingActionStore;
use App\AI\Tool\Support\TaskLookup;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use Psr\Log\LoggerInterface;

class BulkOperationTool implements AssistantTool
{
    public function __construct(
        private readonly TaskLookup $lookup,
        private readonly PendingActionStore $pendingStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'bulk_operation';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Выполнить операцию над несколькими задачами одновременно. Используй когда
        пользователь сказал что-то общее («я всё сделал», «отложи всё про дачу
        на выходные», «отмени всё про работу»).

        Параметр `operation` — что делать: mark_done, snooze, set_priority, cancel.
        `task_ids_or_queries` — массив строк, каждая идентифицирует задачу
        (UUID или фраза для семантического поиска через TaskMatcher).
        `params` — параметры операции:
        - snooze: {until_iso: "2026-04-26T18:00:00+03:00"}
        - set_priority: {priority: "high" | "medium" | "low" | "urgent"}
        - mark_done / cancel: пустой объект {}

        ВСЕГДА требует подтверждения — возвращает PENDING_CONFIRMATION:bulk_<op>:<id>.
        В reply сформулируй понятное превью и вставь маркер [CONFIRM:<id>].

        Если найдено меньше 2 задач — вызови соответствующий одиночный tool
        (mark_task_done / snooze_task / update_task / cancel_task).
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['mark_done', 'snooze', 'set_priority', 'cancel'],
                ],
                'task_ids_or_queries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Список UUID или семантических запросов для каждой задачи',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Параметры операции (until_iso для snooze, priority для set_priority)',
                ],
            ],
            'required' => ['operation', 'task_ids_or_queries'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $operation = (string) ($input['operation'] ?? '');
        $refs = (array) ($input['task_ids_or_queries'] ?? []);
        $params = (array) ($input['params'] ?? []);

        if (!in_array($operation, ['mark_done', 'snooze', 'set_priority', 'cancel'], true)) {
            return ToolResult::error('operation должен быть mark_done | snooze | set_priority | cancel');
        }
        if ($refs === []) {
            return ToolResult::error('task_ids_or_queries не должен быть пустым');
        }

        // Резолвим каждый ref. Если что-то не нашли или нашли неоднозначно —
        // возвращаем ошибку с пояснением, не предлагаем bulk на части.
        $tasks = [];
        $errors = [];
        $seen = [];
        foreach ($refs as $ref) {
            if (!is_string($ref) || trim($ref) === '') {
                continue;
            }
            $found = $this->lookup->resolve($user, '', trim($ref));
            if ($found instanceof ToolResult) {
                $errors[] = "«{$ref}»: " . mb_substr($found->content, 0, 100);
                continue;
            }
            $id = $found->getId()->toRfc4122();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $tasks[] = $found;
        }

        if ($tasks === []) {
            $msg = 'Ни одной задачи не нашёл по запросам.';
            if ($errors !== []) {
                $msg .= "\n" . implode("\n", $errors);
            }

            return ToolResult::error($msg);
        }

        // Валидация params под операцию
        if ($operation === 'snooze') {
            $until = isset($params['until_iso']) ? trim((string) $params['until_iso']) : '';
            if ($until === '') {
                return ToolResult::error('Для bulk snooze нужен params.until_iso');
            }
            try {
                $dt = (new \DateTimeImmutable($until))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                return ToolResult::error('until_iso невалиден: ' . $e->getMessage());
            }
            if ($dt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                return ToolResult::error('until_iso должен быть в будущем');
            }
            $params['until_iso'] = $dt->format(\DateTimeInterface::ATOM);
        }
        if ($operation === 'set_priority') {
            $p = TaskPriority::tryFrom((string) ($params['priority'] ?? ''));
            if ($p === null) {
                return ToolResult::error('Для bulk set_priority нужен params.priority (low|medium|high|urgent)');
            }
            $params['priority'] = $p->value;
        }

        $actionType = 'bulk_' . $operation;
        $taskIds = array_map(fn (Task $t) => $t->getId()->toRfc4122(), $tasks);

        $description = $this->buildDescription($operation, $tasks, $params, $user);

        $action = new PendingAction(
            userId: $user->getId()->toRfc4122(),
            actionType: $actionType,
            description: $description,
            payload: array_merge(['task_ids' => $taskIds], $params),
            createdAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $confirmId = $this->pendingStore->create($user, $action);

        $this->logger->info('BulkOperationTool: pending', [
            'operation' => $operation,
            'count' => count($tasks),
            'confirmation_id' => $confirmId,
        ]);

        return ToolResult::ok(
            "PENDING_CONFIRMATION:{$actionType}:{$confirmId}\n{$description}",
            ['confirmation_id' => $confirmId, 'pending' => true, 'count' => count($tasks)],
        );
    }

    /**
     * @param Task[] $tasks
     */
    private function buildDescription(string $operation, array $tasks, array $params, User $user): string
    {
        $verb = match ($operation) {
            'mark_done' => 'Пометить выполненными',
            'snooze' => 'Отложить',
            'set_priority' => 'Изменить приоритет на ' . ($params['priority'] ?? '?'),
            'cancel' => 'Отменить',
        };
        $extra = '';
        if ($operation === 'snooze' && isset($params['until_iso'])) {
            $userTz = new \DateTimeZone($user->getTimezone());
            $dt = new \DateTimeImmutable($params['until_iso']);
            $extra = ' до ' . $dt->setTimezone($userTz)->format('Y-m-d H:i');
        }
        $list = '';
        foreach ($tasks as $i => $t) {
            $list .= "\n" . ($i + 1) . '. ' . $t->getTitle();
        }

        return "{$verb} задач: " . count($tasks) . $extra . "\n" . $list;
    }
}
