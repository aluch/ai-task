<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Service\PaginationStore;
use App\Service\TelegramUserResolver;
use App\Telegram\Paginator;
use SergiX44\Nutgram\Nutgram;

class ListHandler
{
    public const PAGE_SIZE = 10;

    private const PRIORITY_EMOJI = [
        'urgent' => '🔴',
        'high' => '🔥',
        'medium' => '',
        'low' => '🔽',
    ];

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly PaginationStore $paginationStore,
        private readonly Paginator $paginator,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);

        $text = $bot->message()?->text ?? '';
        $filterArg = trim(substr($text, 5)); // strip "/list"
        [$statuses, $filterLabel] = $this->resolveFilter($filterArg);

        if ($statuses === 'invalid') {
            $bot->sendMessage(text: "Не понял фильтр «{$filterArg}».\nДоступно: /list, /list все, /list done, /list snoozed");

            return;
        }

        $this->renderFirstPage($bot, $user, $statuses, $filterLabel);
    }

    /**
     * @param TaskStatus[]|null $statuses
     */
    public function renderFirstPage(
        Nutgram $bot,
        User $user,
        ?array $statuses,
        ?string $filterLabel,
        ?int $editMessageId = null,
    ): void {
        $total = $this->tasks->countForUser($user, $statuses);

        if ($total === 0) {
            $emptyMsg = $filterLabel === null
                ? 'Нет открытых задач. Отправь текст — создам новую.'
                : "Нет задач по фильтру «{$filterLabel}».";
            if ($editMessageId !== null) {
                $bot->editMessageText(text: $emptyMsg, message_id: $editMessageId, reply_markup: null);
            } else {
                $bot->sendMessage(text: $emptyMsg);
            }

            return;
        }

        $sessionKey = $this->paginationStore->create(
            userId: $user->getId()->toRfc4122(),
            action: 'list',
            filter: ['statuses' => array_map(fn (TaskStatus $s) => $s->value, $statuses ?? TaskRepository::ACTIVE_STATUSES), 'filter_label' => $filterLabel],
            total: $total,
        );

        $this->renderPage($bot, $user, $sessionKey, $statuses, $filterLabel, 1, $total, $editMessageId);
    }

    /**
     * @param TaskStatus[]|null $statuses
     */
    public function renderPage(
        Nutgram $bot,
        User $user,
        string $sessionKey,
        ?array $statuses,
        ?string $filterLabel,
        int $page,
        int $total,
        ?int $editMessageId = null,
    ): void {
        $totalPages = (int) ceil($total / self::PAGE_SIZE);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $tasks = $this->tasks->findForUserPaginated($user, $statuses, self::PAGE_SIZE, $offset);

        $userTz = new \DateTimeZone($user->getTimezone());

        $headerParts = [];
        if ($filterLabel !== null) {
            $headerParts[] = "📋 Фильтр: {$filterLabel}";
        } else {
            $headerParts[] = "📋 Твои задачи ({$total})";
        }
        $lines = [implode("\n", $headerParts), ''];

        foreach ($tasks as $i => $task) {
            $num = $offset + $i + 1;
            $lines[] = $this->formatTask($num, $task, $userTz);
        }

        if ($totalPages > 1) {
            $lines[] = '';
            $lines[] = "Страница {$page}/{$totalPages}";
        }

        $keyboard = $this->paginator->buildListKeyboard($sessionKey, $page, $totalPages);

        $text = implode("\n", $lines);
        if ($editMessageId !== null) {
            $bot->editMessageText(text: $text, message_id: $editMessageId, reply_markup: $keyboard);
        } else {
            $bot->sendMessage(text: $text, reply_markup: $keyboard);
        }
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
