<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Entity\User;
use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use App\Service\PaginationStore;
use App\Service\TaskIdResolver;
use App\Service\TelegramUserResolver;
use App\Telegram\Paginator;
use SergiX44\Nutgram\Nutgram;

class DepsHandler
{
    public const PAGE_SIZE = 5;
    public const ACTION = 'deps';
    public const MENU_PREFIX = 'deps:m';

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly TaskIdResolver $idResolver,
        private readonly PaginationStore $paginationStore,
        private readonly Paginator $paginator,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $arg = trim(substr($text, 6)); // strip "/deps"
        if ($arg === '') {
            $this->renderFirstPage($bot, $user);

            return;
        }

        try {
            $task = $this->idResolver->resolve($arg, $user);
        } catch (TaskIdException $e) {
            $bot->sendMessage(text: $e->getMessage());

            return;
        }

        $bot->sendMessage(text: $this->formatDeps($task));
    }

    public function renderFirstPage(
        Nutgram $bot,
        User $user,
        string $search = '',
        ?int $editMessageId = null,
    ): void {
        $total = $this->tasks->countForUser($user, null, $search);

        if ($total === 0) {
            $msg = $search === ''
                ? 'Нет открытых задач.'
                : "По запросу «{$search}» ничего не нашёл.";
            if ($editMessageId !== null) {
                $bot->editMessageText(text: $msg, message_id: $editMessageId, reply_markup: null);
            } else {
                $bot->sendMessage(text: $msg);
            }

            return;
        }

        $sessionKey = $this->paginationStore->create(
            userId: $user->getId()->toRfc4122(),
            action: self::ACTION,
            filter: ['search' => $search],
            total: $total,
        );

        $this->renderPage($bot, $user, $sessionKey, $search, 1, $total, $editMessageId);
    }

    public function renderPage(
        Nutgram $bot,
        User $user,
        string $sessionKey,
        string $search,
        int $page,
        int $total,
        ?int $editMessageId = null,
    ): void {
        $totalPages = (int) ceil($total / self::PAGE_SIZE);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $tasks = $this->tasks->findForUserPaginated($user, null, self::PAGE_SIZE, $offset, $search);

        $header = $search === ''
            ? 'Зависимости какой задачи посмотреть?'
            : "Результаты по «{$search}»:";

        $keyboard = $this->paginator->buildTaskPickerKeyboard(
            tasks: $tasks,
            labelBuilder: fn (Task $t) => $this->truncate($t->getTitle(), 30),
            selectCallbackBuilder: fn (Task $t) => 'deps:' . $t->getId()->toRfc4122(),
            menuPrefix: self::MENU_PREFIX,
            sessionKey: $sessionKey,
            currentPage: $page,
            totalPages: $totalPages,
        );

        if ($editMessageId !== null) {
            $bot->editMessageText(text: $header, message_id: $editMessageId, reply_markup: $keyboard);
        } else {
            $bot->sendMessage(text: $header, reply_markup: $keyboard);
        }
    }

    public function showDepsById(Nutgram $bot, User $user, string $uuidOrPrefix, bool $editMessage): void
    {
        try {
            $task = $this->idResolver->resolve($uuidOrPrefix, $user);
        } catch (TaskIdException $e) {
            $this->reply($bot, $e->getMessage(), $editMessage);

            return;
        }

        $this->reply($bot, $this->formatDeps($task), $editMessage);
    }

    private function formatDeps(Task $task): string
    {
        $lines = ["📋 {$task->getTitle()}", ''];

        $blockers = $task->getBlockedBy()->toArray();
        $lines[] = '⛔ Заблокирована:';
        if ($blockers === []) {
            $lines[] = '  (ничего)';
        } else {
            foreach ($blockers as $b) {
                $uuid = $b->getId()->toRfc4122();
                $lines[] = "  • {$b->getTitle()} ({$b->getStatus()->value}) — {$uuid}";
            }
        }

        $lines[] = '';

        $blocked = $task->getBlockedTasks()->toArray();
        $lines[] = '🔓 Блокирует:';
        if ($blocked === []) {
            $lines[] = '  (ничего)';
        } else {
            foreach ($blocked as $b) {
                $uuid = $b->getId()->toRfc4122();
                $lines[] = "  • {$b->getTitle()} ({$b->getStatus()->value}) — {$uuid}";
            }
        }

        return implode("\n", $lines);
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . '…';
    }

    private function reply(Nutgram $bot, string $text, bool $edit): void
    {
        if ($edit) {
            $bot->editMessageText(text: $text, reply_markup: null);
        } else {
            $bot->sendMessage(text: $text);
        }
    }
}
