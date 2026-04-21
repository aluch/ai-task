<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\Task;
use App\Entity\TaskContext;
use App\Entity\User;
use App\Enum\TaskPriority;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class UpdateTaskTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskLookup $lookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'update_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Обновить существующую задачу — изменить title, описание, дедлайн, приоритет, контексты,
        оценку времени, интервалы напоминаний. Используй когда пользователь хочет скорректировать
        задачу: «перенеси на завтра», «добавь дедлайн», «сделай срочной», «уточню детали» и т.п.

        Передавай в updates только те поля, которые нужно изменить — остальные останутся как были.
        Для сброса дедлайна передай deadline_iso=null или пустую строку.
        Для context_codes передавай полный желаемый список — он заменяет существующие контексты.

        Если менялся deadline или remind_before_deadline_minutes — автоматически сбросится
        deadline_reminder_sent_at, чтобы новое напоминание успело отправиться.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'string',
                    'description' => 'Полный UUID задачи.',
                ],
                'task_query' => [
                    'type' => 'string',
                    'description' => 'Часть названия задачи для поиска (case-insensitive).',
                ],
                'updates' => [
                    'type' => 'object',
                    'description' => 'Поля для обновления. Передавай только те, что нужно изменить.',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'deadline_iso' => [
                            'type' => 'string',
                            'description' => 'ISO 8601 с timezone пользователя. Пустая строка/null — убрать дедлайн.',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'medium', 'high', 'urgent'],
                        ],
                        'estimated_minutes' => ['type' => 'integer'],
                        'context_codes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Полный список контекстов — заменяет существующие.',
                        ],
                        'remind_before_deadline_minutes' => ['type' => 'integer'],
                        'reminder_interval_minutes' => ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['updates'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $updates = is_array($input['updates'] ?? null) ? $input['updates'] : [];
        if ($updates === []) {
            return ToolResult::error('updates не должен быть пустым.');
        }

        $found = $this->lookup->resolve(
            $user,
            trim((string) ($input['task_id'] ?? '')),
            trim((string) ($input['task_query'] ?? '')),
        );
        if ($found instanceof ToolResult) {
            return $found;
        }
        $task = $found;
        $em = $this->doctrine->getManager();

        $changes = [];
        $deadlineChanged = false;
        $remindBeforeChanged = false;

        if (array_key_exists('title', $updates)) {
            $new = trim((string) $updates['title']);
            if ($new !== '') {
                $task->setTitle(mb_substr($new, 0, 255));
                $changes[] = 'title';
            }
        }

        if (array_key_exists('description', $updates)) {
            $desc = $updates['description'];
            $task->setDescription(
                is_string($desc) && $desc !== '' ? $desc : null,
            );
            $changes[] = 'description';
        }

        if (array_key_exists('deadline_iso', $updates)) {
            $deadlineChanged = true;
            $iso = $updates['deadline_iso'];
            if ($iso === null || $iso === '' || $iso === 'null') {
                $task->setDeadline(null);
                $changes[] = 'deadline: убран';
            } else {
                try {
                    $dt = new \DateTimeImmutable((string) $iso);
                    $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
                    $task->setDeadline($utc);
                    $local = $utc->setTimezone(new \DateTimeZone($user->getTimezone()));
                    $changes[] = 'deadline: ' . $local->format('d.m H:i');
                } catch (\Exception $e) {
                    return ToolResult::error('Не удалось распарсить deadline_iso: ' . $e->getMessage());
                }
            }
        }

        if (array_key_exists('priority', $updates)) {
            $p = TaskPriority::tryFrom((string) $updates['priority']);
            if ($p !== null) {
                $task->setPriority($p);
                $changes[] = 'priority=' . $p->value;
            }
        }

        if (array_key_exists('estimated_minutes', $updates)) {
            $em_min = $updates['estimated_minutes'];
            if (is_int($em_min) && $em_min > 0) {
                $task->setEstimatedMinutes($em_min);
                $changes[] = "оценка={$em_min}мин";
            }
        }

        if (array_key_exists('context_codes', $updates) && is_array($updates['context_codes'])) {
            $codes = array_values(array_filter(array_map('strval', $updates['context_codes'])));
            // удалить все текущие
            foreach ($task->getContexts()->toArray() as $existing) {
                $task->removeContext($existing);
            }
            if ($codes !== []) {
                $found = $em->getRepository(TaskContext::class)
                    ->createQueryBuilder('c')
                    ->andWhere('c.code IN (:codes)')
                    ->setParameter('codes', $codes)
                    ->getQuery()
                    ->getResult();
                $foundCodes = array_map(fn (TaskContext $c) => $c->getCode(), $found);
                $unknown = array_diff($codes, $foundCodes);
                if ($unknown !== []) {
                    $this->logger->warning('update_task: unknown context codes', ['codes' => $unknown]);
                }
                foreach ($found as $c) {
                    $task->addContext($c);
                }
            }
            $changes[] = 'контексты=[' . implode(',', $codes) . ']';
        }

        if (array_key_exists('remind_before_deadline_minutes', $updates)) {
            $remindBeforeChanged = true;
            $n = $updates['remind_before_deadline_minutes'];
            $task->setRemindBeforeDeadlineMinutes(is_int($n) && $n > 0 ? $n : null);
            $changes[] = 'remind_before=' . ($n ?? 'null');
        }

        if (array_key_exists('reminder_interval_minutes', $updates)) {
            $n = $updates['reminder_interval_minutes'];
            if (is_int($n) && $n > 0) {
                $task->setReminderIntervalMinutes(max(60, $n));
                $changes[] = 'interval=' . max(60, $n) . 'мин';
            } elseif ($n === null) {
                $task->setReminderIntervalMinutes(null);
                $changes[] = 'interval: убран';
            }
        }

        // Сбрасываем «уже отправлено» чтобы новое напоминание сработало.
        if ($deadlineChanged || $remindBeforeChanged) {
            $task->setDeadlineReminderSentAt(null);
        }

        if ($changes === []) {
            return ToolResult::error('В updates нет поддерживаемых полей.');
        }

        $em->flush();

        $this->logger->info('Assistant updated task', [
            'task_id' => $task->getId()->toRfc4122(),
            'changes' => $changes,
        ]);

        return ToolResult::ok(
            "Обновлено: «{$task->getTitle()}». Изменения: " . implode(', ', $changes),
            ['task_id' => $task->getId()->toRfc4122()],
        );
    }
}
