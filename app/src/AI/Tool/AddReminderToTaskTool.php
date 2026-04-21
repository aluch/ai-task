<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class AddReminderToTaskTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskLookup $lookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'add_reminder_to_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Добавить или изменить напоминание о существующей задаче. Используй когда
        пользователь просит «напомни про X за час до дедлайна», «напоминай про Y
        каждые 2 часа», «пингай меня пока не сделаю».

        Должен быть указан хотя бы один из параметров:
        - remind_before_deadline_minutes — за сколько минут до дедлайна. Задача
          ДОЛЖНА иметь deadline, иначе ошибка.
        - reminder_interval_minutes — интервал периодических. Минимум 60 минут;
          меньшие значения автоматически поднимаются до 60.

        После апдейта сбрасывается deadline_reminder_sent_at (для Типа А) и
        last_reminded_at (для Типа Б) чтобы новое напоминание отработало.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id_or_query' => [
                    'type' => 'string',
                    'description' => 'UUID или часть названия задачи.',
                ],
                'remind_before_deadline_minutes' => [
                    'type' => 'integer',
                    'description' => 'Для задачи с дедлайном — за сколько минут до него напомнить.',
                ],
                'reminder_interval_minutes' => [
                    'type' => 'integer',
                    'description' => 'Для периодических — интервал. Минимум 60 минут.',
                ],
            ],
            'required' => ['task_id_or_query'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $ref = trim((string) ($input['task_id_or_query'] ?? ''));
        if ($ref === '') {
            return ToolResult::error('task_id_or_query обязателен.');
        }

        $remindBefore = isset($input['remind_before_deadline_minutes']) && is_int($input['remind_before_deadline_minutes'])
            ? $input['remind_before_deadline_minutes']
            : null;
        $interval = isset($input['reminder_interval_minutes']) && is_int($input['reminder_interval_minutes'])
            ? $input['reminder_interval_minutes']
            : null;

        if ($remindBefore === null && $interval === null) {
            return ToolResult::error('Нужен хотя бы один из remind_before_deadline_minutes / reminder_interval_minutes.');
        }

        $found = \Symfony\Component\Uid\Uuid::isValid($ref)
            ? $this->lookup->resolve($user, $ref, '')
            : $this->lookup->resolve($user, '', $ref);
        if ($found instanceof ToolResult) {
            return $found;
        }
        $task = $found;

        $changes = [];

        if ($remindBefore !== null) {
            if ($remindBefore <= 0) {
                return ToolResult::error('remind_before_deadline_minutes должно быть положительным числом.');
            }
            if ($task->getDeadline() === null) {
                return ToolResult::error(
                    "У задачи «{$task->getTitle()}» нет дедлайна — не могу поставить напоминание ДО него. "
                    . 'Хочешь поставить периодическое (reminder_interval_minutes) или сначала задать дедлайн через update_task?',
                );
            }
            $task->setRemindBeforeDeadlineMinutes($remindBefore);
            $task->setDeadlineReminderSentAt(null);
            $changes[] = "remind_before={$remindBefore}мин";
        }

        if ($interval !== null) {
            if ($interval <= 0) {
                return ToolResult::error('reminder_interval_minutes должно быть положительным.');
            }
            $effective = max(60, $interval);
            $task->setReminderIntervalMinutes($effective);
            $task->setLastRemindedAt(null);
            if ($effective !== $interval) {
                $changes[] = "interval={$effective}мин (запрошенные {$interval} ниже минимума 60)";
            } else {
                $changes[] = "interval={$effective}мин";
            }
        }

        $this->doctrine->getManager()->flush();

        $this->logger->info('Assistant add_reminder_to_task', [
            'task_id' => $task->getId()->toRfc4122(),
            'changes' => $changes,
        ]);

        return ToolResult::ok(
            "Настроил напоминание для «{$task->getTitle()}»: " . implode(', ', $changes),
            ['task_id' => $task->getId()->toRfc4122()],
        );
    }
}
