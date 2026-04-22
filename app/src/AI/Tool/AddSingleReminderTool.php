<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class AddSingleReminderTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskLookup $lookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'add_single_reminder';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Установить одноразовое напоминание о задаче на конкретное время.
        Используй когда пользователь явно говорит «напомни мне про X в HH:MM»,
        «напомни в 6:50», «в пятницу в 18:00 напомни». В отличие от snooze_task,
        задача остаётся активной и не исчезает из списков — это просто
        «пинг в заданный момент».

        Правила:
        - at_iso — ISO 8601 с timezone пользователя (не UTC). Должно быть
          в будущем.
        - По умолчанию quiet hours пользователя НЕ соблюдаются: если человек
          сам просит в 6:50 — он в 6:50 и получит. Передай
          respect_quiet_hours=true только если пользователь явно согласен
          подождать утра.
        - Если у задачи уже было single_reminder_at — перезаписывается
          (одновременно действует только одно single-напоминание на задачу).
        - Если пользователь сказал одно и то же время несколько раз подряд —
          всё равно перезапишется, один активный таймер.
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
                'at_iso' => [
                    'type' => 'string',
                    'description' => 'ISO 8601 datetime (с timezone пользователя) — когда напомнить. В будущем.',
                ],
                'respect_quiet_hours' => [
                    'type' => 'boolean',
                    'description' => 'Уважать ли quiet hours пользователя. По умолчанию false.',
                ],
            ],
            'required' => ['task_id_or_query', 'at_iso'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $ref = trim((string) ($input['task_id_or_query'] ?? ''));
        if ($ref === '') {
            return ToolResult::error('task_id_or_query обязателен.');
        }

        $atRaw = trim((string) ($input['at_iso'] ?? ''));
        if ($atRaw === '') {
            return ToolResult::error('at_iso обязателен.');
        }

        try {
            $at = new \DateTimeImmutable($atRaw);
        } catch (\Exception $e) {
            return ToolResult::error('Не удалось распарсить at_iso: ' . $e->getMessage());
        }

        $utc = new \DateTimeZone('UTC');
        $atUtc = $at->setTimezone($utc);
        $now = new \DateTimeImmutable('now', $utc);
        if ($atUtc <= $now) {
            return ToolResult::error('Время напоминания должно быть в будущем.');
        }

        $found = \Symfony\Component\Uid\Uuid::isValid($ref)
            ? $this->lookup->resolve($user, $ref, '')
            : $this->lookup->resolve($user, '', $ref);
        if ($found instanceof ToolResult) {
            return $found;
        }
        $task = $found;

        $respect = (bool) ($input['respect_quiet_hours'] ?? false);

        $task->setSingleReminderAt($atUtc);
        $task->setSingleReminderSentAt(null); // reset чтобы напоминание сработало
        $task->setSingleReminderRespectQuietHours($respect);
        $this->doctrine->getManager()->flush();

        $this->logger->info('Assistant add_single_reminder', [
            'task_id' => $task->getId()->toRfc4122(),
            'at_utc' => $atUtc->format('c'),
            'respect_quiet_hours' => $respect,
        ]);

        $userTz = new \DateTimeZone($user->getTimezone());
        $localAt = $atUtc->setTimezone($userTz)->format('d.m H:i');

        return ToolResult::ok(
            "Напомню про «{$task->getTitle()}» в {$localAt} ({$user->getTimezone()}).",
            [
                'task_id' => $task->getId()->toRfc4122(),
                'at_utc' => $atUtc->format('c'),
            ],
        );
    }
}
