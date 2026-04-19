<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;

class ListTasksTool implements AssistantTool
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function getName(): string
    {
        return 'list_tasks';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Показать задачи пользователя. Используй когда пользователь спрашивает что у него есть, что запланировано, что сделано.
        Опционально фильтруй по статусу (active = pending + in_progress по умолчанию) или поисковому запросу в названии.
        Возвращает компактный список с id задач — используй их потом для mark_task_done / snooze_task если нужно.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status_filter' => [
                    'type' => 'string',
                    'enum' => ['active', 'done', 'snoozed', 'all'],
                    'description' => 'Фильтр по статусу. По умолчанию active.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Поиск в названии задачи (частичное совпадение, case-insensitive). Опционально.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Сколько задач вернуть. По умолчанию 20.',
                ],
            ],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $statusFilter = $input['status_filter'] ?? 'active';
        $query = isset($input['query']) ? trim((string) $input['query']) : '';
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0 || $limit > 100) {
            $limit = self::DEFAULT_LIMIT;
        }

        $statuses = match ($statusFilter) {
            'done' => [TaskStatus::DONE],
            'snoozed' => [TaskStatus::SNOOZED],
            'all' => [],
            default => [TaskStatus::PENDING, TaskStatus::IN_PROGRESS],
        };

        $em = $this->doctrine->getManager();
        $qb = $em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($statuses !== []) {
            $qb->andWhere('t.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        if ($query !== '') {
            $qb->andWhere('LOWER(t.title) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }

        /** @var Task[] $tasks */
        $tasks = $qb->getQuery()->getResult();

        if ($tasks === []) {
            return ToolResult::ok('Задач по фильтру не найдено.');
        }

        $userTz = new \DateTimeZone($user->getTimezone());
        $lines = [];
        foreach ($tasks as $i => $t) {
            $n = $i + 1;
            $parts = [
                "[id:{$t->getId()->toRfc4122()}]",
                $t->getTitle(),
                "({$t->getStatus()->value}",
            ];
            if ($t->getPriority()->value !== 'medium') {
                $parts[count($parts) - 1] .= ', ' . $t->getPriority()->value;
            }
            if ($t->getDeadline() !== null) {
                $parts[count($parts) - 1] .= ', дедлайн: ' . $t->getDeadline()->setTimezone($userTz)->format('Y-m-d H:i');
            }
            $parts[count($parts) - 1] .= ')';
            $lines[] = "{$n}. " . implode(' ', $parts);
        }

        return ToolResult::ok(
            implode("\n", $lines),
            ['count' => count($tasks)],
        );
    }
}
