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

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $userTz = new \DateTimeZone($user->getTimezone());

        $text = $bot->message()?->text ?? '';
        $filterArg = trim(substr($text, 5)); // strip "/list"
        [$statuses, $filterLabel] = $this->resolveFilter($filterArg);

        if ($statuses === 'invalid') {
            $bot->sendMessage(text: "Не понял фильтр «{$filterArg}».\nДоступно: /list, /list все, /list done, /list snoozed");

            return;
        }

        $tasks = $this->tasks->findForUser($user, $statuses, limit: self::LIMIT + 1);

        $hasMore = count($tasks) > self::LIMIT;
        $tasks = array_slice($tasks, 0, self::LIMIT);

        if ($tasks === []) {
            $emptyMsg = $filterLabel === null
                ? 'Нет открытых задач. Отправь текст — создам новую.'
                : "Нет задач по фильтру «{$filterLabel}».";
            $bot->sendMessage(text: $emptyMsg);

            return;
        }

        // Разделяем: незаблокированные вверху, заблокированные внизу
        $unblocked = [];
        $blocked = [];
        foreach ($tasks as $task) {
            if ($task->isBlocked()) {
                $blocked[] = $task;
            } else {
                $unblocked[] = $task;
            }
        }

        $sorted = array_merge($unblocked, $blocked);

        $lines = [];
        if ($filterLabel !== null) {
            $lines[] = "📋 Фильтр: {$filterLabel}";
            $lines[] = '';
        }
        foreach ($sorted as $i => $task) {
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

        $deadlineStr = '';
        if ($task->getDeadline() !== null) {
            $deadlineStr = ' ⏰ ' . $this->formatDeadline($task->getDeadline(), $userTz);
        }

        $statusStr = '';
        if ($task->getStatus() === TaskStatus::SNOOZED && $task->getSnoozedUntil() !== null) {
            $statusStr = ' 💤 до ' . $task->getSnoozedUntil()->setTimezone($userTz)->format('d.m H:i');
        }

        $blockedStr = $task->isBlocked() ? ' ⛔' : '';
        $priStr = $priEmoji !== '' ? " {$priEmoji}" : '';

        return "{$num}. {$title}{$deadlineStr}{$priStr}{$statusStr}{$blockedStr}";
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

    /**
     * Разбирает аргумент /list в (statuses, label).
     * - '' → (null, null) — дефолт, активные без заголовка
     * - 'все' | 'all' → ([], 'все статусы')
     * - 'done' | 'выполнено' → ([DONE], 'выполненные')
     * - 'snoozed' | 'отложенные' → ([SNOOZED], 'отложенные')
     * - неизвестное → ('invalid', null)
     *
     * @return array{0: TaskStatus[]|null|'invalid', 1: ?string}
     */
    private function resolveFilter(string $arg): array
    {
        $normalized = mb_strtolower(trim($arg));

        return match ($normalized) {
            '' => [null, null],
            'все', 'all' => [[], 'все статусы'],
            'done', 'выполнено', 'выполненные' => [[TaskStatus::DONE], 'выполненные'],
            'snoozed', 'отложенные', 'отложено' => [[TaskStatus::SNOOZED], 'отложенные'],
            default => ['invalid', null],
        };
    }
}
