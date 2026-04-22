<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class SnoozeTaskTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'snooze_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Отложить задачу на определённое время. Используй когда пользователь просит отложить, перенести, напомнить позже.
        Принимает либо task_id (полный UUID), либо task_query (часть названия для поиска).
        until_iso — ISO 8601 datetime с timezone пользователя (не UTC). Например, "2026-04-20T18:00:00+03:00".
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'string',
                    'description' => 'Полный UUID задачи (36 символов).',
                ],
                'task_query' => [
                    'type' => 'string',
                    'description' => 'Часть названия для поиска (case-insensitive).',
                ],
                'until_iso' => [
                    'type' => 'string',
                    'description' => 'ISO 8601 datetime с timezone пользователя, когда задача должна снова стать активной.',
                ],
            ],
            'required' => ['until_iso'],
        ];
    }

    /**
     * Примитивный морфо-стемминг: отрезаем 2 последних символа если слово >3.
     * «тренировку» → «тренировк» → находит «тренировка», «тренировки».
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
        $untilRaw = isset($input['until_iso']) ? trim((string) $input['until_iso']) : '';
        if ($untilRaw === '') {
            return ToolResult::error('until_iso обязателен');
        }

        try {
            $until = new \DateTimeImmutable($untilRaw);
        } catch (\Exception $e) {
            return ToolResult::error('Не удалось распарсить until_iso: ' . $e->getMessage());
        }

        $utc = new \DateTimeZone('UTC');
        $untilUtc = $until->setTimezone($utc);
        $now = new \DateTimeImmutable('now', $utc);

        if ($untilUtc <= $now) {
            return ToolResult::error('Время snooze должно быть в будущем.');
        }

        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Task::class);

        $taskId = isset($input['task_id']) ? trim((string) $input['task_id']) : '';
        $taskQuery = isset($input['task_query']) ? trim((string) $input['task_query']) : '';

        $task = null;

        if ($taskId !== '') {
            if (!Uuid::isValid($taskId)) {
                return ToolResult::error('Неверный формат UUID.');
            }
            $task = $repo->find(Uuid::fromString($taskId));
            if ($task === null || !$task->getUser()->getId()->equals($user->getId())) {
                return ToolResult::error('Задача не найдена.');
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

            $this->logger->info('snooze_task search', [
                'query' => $taskQuery,
                'search_root' => $searchRoot,
                'found' => array_map(fn ($t) => [
                    'id' => $t->getId()->toRfc4122(),
                    'title' => $t->getTitle(),
                ], $matches),
            ]);

            if ($matches === []) {
                return ToolResult::error("Задача с «{$taskQuery}» не найдена.");
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

        $task->snooze($untilUtc);
        // Пользователь явно выбрал время — quiet hours не применяем
        // при разбуживании. Если автоматический snooze (через кнопку
        // «отложить на час» в напоминании) хочет поведение по умолчанию,
        // он использует Task::snooze() и не трогает respectQuietHours.
        $task->setRespectQuietHours(false);
        $em->flush();

        $userTz = new \DateTimeZone($user->getTimezone());
        $localUntil = $untilUtc->setTimezone($userTz)->format('Y-m-d H:i');

        $this->logger->info('Assistant snoozed task', [
            'task_id' => $task->getId()->toRfc4122(),
            'until_utc' => $untilUtc->format('c'),
        ]);

        return ToolResult::ok(
            "Задача «{$task->getTitle()}» отложена до {$localUntil} ({$user->getTimezone()}).",
            [
                'task_id' => $task->getId()->toRfc4122(),
                'until_utc' => $untilUtc->format('c'),
            ],
        );
    }
}
