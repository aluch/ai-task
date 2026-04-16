<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Service\TelegramUserResolver;
use SergiX44\Nutgram\Nutgram;

class ListHandler
{
    private const LIMIT = 10;

    private const PRIORITY_EMOJI = [
        'urgent' => '🔴',
        'high' => '🔥',
        'medium' => '',
        'low' => '🔽',
    ];

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $userTz = new \DateTimeZone($user->getTimezone());

        $tasks = $this->tasks->findForUser($user, limit: self::LIMIT + 1);

        // Подсчёт всех для подсказки "показаны X из Y"
        $hasMore = count($tasks) > self::LIMIT;
        $tasks = array_slice($tasks, 0, self::LIMIT);

        if ($tasks === []) {
            $bot->sendMessage(text: 'Нет открытых задач. Отправь текст — создам новую.');

            return;
        }

        $lines = [];
        foreach ($tasks as $i => $task) {
            $lines[] = $this->formatTask($i + 1, $task, $userTz);
        }

        if ($hasMore) {
            $lines[] = '';
            $lines[] = '…показаны первые ' . self::LIMIT . '. Закрой часть через /done чтобы увидеть остальные.';
        }

        $bot->sendMessage(text: implode("\n", $lines));
    }

    private function formatTask(int $num, Task $task, \DateTimeZone $userTz): string
    {
        $title = $task->getTitle();
        $priEmoji = self::PRIORITY_EMOJI[$task->getPriority()->value] ?? '';
        $shortId = substr($task->getId()->toRfc4122(), 0, 8);

        $deadlineStr = '';
        if ($task->getDeadline() !== null) {
            $deadlineStr = ' ⏰ ' . $this->formatDeadline($task->getDeadline(), $userTz);
        }

        $statusStr = '';
        if ($task->getStatus() === TaskStatus::SNOOZED && $task->getSnoozedUntil() !== null) {
            $statusStr = ' 💤 до ' . $task->getSnoozedUntil()->setTimezone($userTz)->format('d.m H:i');
        }

        $priStr = $priEmoji !== '' ? " {$priEmoji}" : '';

        return "{$num}. {$title}{$deadlineStr}{$priStr}{$statusStr}\n   ID: {$shortId}";
    }

    private function formatDeadline(\DateTimeImmutable $deadline, \DateTimeZone $userTz): string
    {
        $now = new \DateTimeImmutable('now', $userTz);
        $local = $deadline->setTimezone($userTz);

        $today = $now->format('Y-m-d');
        $deadlineDate = $local->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        if ($deadlineDate === $today) {
            return 'сегодня ' . $local->format('H:i');
        }

        if ($deadlineDate === $tomorrow) {
            return 'завтра ' . $local->format('H:i');
        }

        $diff = $now->diff($local);
        if (!$diff->invert && $diff->days <= 7) {
            return 'через ' . $diff->days . ' ' . $this->pluralizeDays($diff->days);
        }

        return $local->format('d.m H:i');
    }

    private function pluralizeDays(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'день';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return 'дня';
        }

        return 'дней';
    }
}
