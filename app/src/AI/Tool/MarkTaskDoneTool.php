<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class MarkTaskDoneTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly TaskLookup $lookup,
    ) {
    }

    public function getName(): string
    {
        return 'mark_task_done';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Пометить задачу выполненной. Принимает либо полный UUID (task_id), либо
        свободное описание (task_query) — под капотом семантический матчер через
        Haiku понимает русскую морфологию. Если нашлось несколько похожих —
        вернёт success=false со списком; в reply перечисли варианты и попроси
        пользователя написать конкретнее следующим сообщением (вопросов-ожиданий
        не задавай — нет памяти между сообщениями).
        Если не найдено — вернёт success=false.
        Используй когда пользователь сообщает что сделал что-то.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'string',
                    'description' => 'Полный UUID задачи (36 символов). Используй если знаешь точный ID из list_tasks.',
                ],
                'task_query' => [
                    'type' => 'string',
                    'description' => 'Свободное описание задачи для семантического поиска (можно в любой форме слова, с опечатками).',
                ],
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

        $em = $this->doctrine->getManager();

        // Считаем разблокированные
        $wasBlockingBefore = [];
        foreach ($task->getBlockedTasks() as $downstream) {
            if ($downstream->isBlocked()) {
                $wasBlockingBefore[$downstream->getId()->toRfc4122()] = $downstream;
            }
        }

        $task->markDone();
        $em->flush();

        $unblockedCount = 0;
        $unblockedTitles = [];
        foreach ($wasBlockingBefore as $downstream) {
            if (!$downstream->isBlocked()) {
                $unblockedCount++;
                $unblockedTitles[] = $downstream->getTitle();
            }
        }

        $this->logger->info('Assistant marked task done', [
            'task_id' => $task->getId()->toRfc4122(),
            'unblocked_count' => $unblockedCount,
        ]);

        $msg = "Задача «{$task->getTitle()}» помечена выполненной.";
        if ($unblockedCount > 0) {
            $msg .= " Разблокировано задач: {$unblockedCount}";
            if ($unblockedTitles !== []) {
                $msg .= ' (' . implode(', ', $unblockedTitles) . ')';
            }
        }

        return ToolResult::ok(
            $msg,
            [
                'task_id' => $task->getId()->toRfc4122(),
                'unblocked_count' => $unblockedCount,
            ],
        );
    }
}
