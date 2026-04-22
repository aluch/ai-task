<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class SnoozeTaskTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly TaskLookup $lookup,
    ) {
    }

    public function getName(): string
    {
        return 'snooze_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Отложить задачу на определённое время. Используй когда пользователь просит
        отложить, перенести, напомнить позже.
        Принимает либо task_id (полный UUID), либо task_query — свободное описание
        на естественном языке (семантический матчер через Haiku, понимает русскую
        морфологию и разные формы слов).
        until_iso — ISO 8601 datetime с timezone пользователя (не UTC).
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
                    'description' => 'Свободное описание задачи для семантического поиска.',
                ],
                'until_iso' => [
                    'type' => 'string',
                    'description' => 'ISO 8601 datetime с timezone пользователя, когда задача должна снова стать активной.',
                ],
            ],
            'required' => ['until_iso'],
        ];
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
        $task->snooze($untilUtc);
        // Пользователь явно выбрал время — quiet hours не применяем
        // при разбуживании. Автоматический snooze (кнопка «отложить на час»
        // в напоминании) использует Task::snooze() и не трогает respectQuietHours.
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
