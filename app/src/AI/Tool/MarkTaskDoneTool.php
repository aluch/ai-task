<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class MarkTaskDoneTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'mark_task_done';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Пометить задачу выполненной. Принимает либо полный UUID задачи (task_id), либо частичное название (task_query) для поиска.
        Если передан task_query и найдено больше одной задачи — вернёт success=false со списком совпадений, и ты должен спросить пользователя какую именно он имеет в виду.
        Если ни одна задача не найдена — вернёт success=false. Используй когда пользователь сообщает что сделал что-то.
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
                    'description' => 'Часть названия задачи для поиска (case-insensitive).',
                ],
            ],
        ];
    }

    /**
     * Примитивный морфо-стемминг: отрезаем 2 последних символа если слово >3.
     * «тренировку» → «тренировк» → находит «тренировка», «тренировки», etc.
     * Для короткого MVP достаточно; полноценную морфологию добавим если
     * окажется что этого мало.
     */
    private function toSearchRoot(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        if (mb_strlen($normalized) > 3) {
            $normalized = mb_substr($normalized, 0, -2);
        }

        return $normalized;
    }

    public function execute(User $user, array $input): ToolResult
    {
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Task::class);

        $taskId = isset($input['task_id']) ? trim((string) $input['task_id']) : '';
        $taskQuery = isset($input['task_query']) ? trim((string) $input['task_query']) : '';

        $task = null;

        if ($taskId !== '') {
            if (!Uuid::isValid($taskId)) {
                return ToolResult::error('Неверный формат UUID задачи.');
            }
            $task = $repo->find(Uuid::fromString($taskId));
            if ($task === null || !$task->getUser()->getId()->equals($user->getId())) {
                return ToolResult::error('Задача по UUID не найдена.');
            }
        } elseif ($taskQuery !== '') {
            $searchRoot = $this->toSearchRoot($taskQuery);
            $matches = $repo->createQueryBuilder('t')
                ->andWhere('t.user = :user')
                ->andWhere('LOWER(t.title) LIKE :q')
                ->andWhere('t.status IN (:open)')
                ->setParameter('user', $user)
                ->setParameter('q', '%' . $searchRoot . '%')
                ->setParameter('open', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS, TaskStatus::SNOOZED])
                ->getQuery()
                ->getResult();

            $this->logger->info('mark_task_done search', [
                'query' => $taskQuery,
                'search_root' => $searchRoot,
                'found' => array_map(fn ($t) => [
                    'id' => $t->getId()->toRfc4122(),
                    'title' => $t->getTitle(),
                ], $matches),
            ]);

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
            $task = $matches[0];
        } else {
            return ToolResult::error('Нужен task_id или task_query.');
        }

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
