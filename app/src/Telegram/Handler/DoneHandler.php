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
use Doctrine\Persistence\ManagerRegistry;
use SergiX44\Nutgram\Nutgram;

class DoneHandler
{
    public const PAGE_SIZE = 5;
    public const ACTION = 'done';
    public const MENU_PREFIX = 'done:m';

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly TaskIdResolver $idResolver,
        private readonly ManagerRegistry $doctrine,
        private readonly PaginationStore $paginationStore,
        private readonly Paginator $paginator,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $arg = trim(substr($text, 6)); // strip "/done"
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

        $this->markDoneTask($bot, $task, editMessage: false);
    }

    public function renderFirstPage(
        Nutgram $bot,
        User $user,
        string $search = '',
        ?int $editMessageId = null,
    ): void {
        $total = $this->tasks->countUnblockedForUser($user, $search);

        if ($total === 0) {
            $msg = $search === ''
                ? 'Нет доступных задач (незаблокированных).'
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
            filter: ['search' => $search, 'scope' => 'unblocked'],
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

        $tasks = $this->tasks->findUnblockedForUserPaginated($user, self::PAGE_SIZE, $offset, $search);

        $header = $search === ''
            ? 'Какую задачу выполнил?'
            : "Результаты по «{$search}»:";

        $keyboard = $this->paginator->buildTaskPickerKeyboard(
            tasks: $tasks,
            labelBuilder: fn (Task $t) => $this->truncate($t->getTitle(), 30),
            selectCallbackBuilder: fn (Task $t) => 'done:' . $t->getId()->toRfc4122(),
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

    public function markDoneById(Nutgram $bot, User $user, string $uuidOrPrefix, bool $editMessage): void
    {
        try {
            $task = $this->idResolver->resolve($uuidOrPrefix, $user);
        } catch (TaskIdException $e) {
            $this->reply($bot, $e->getMessage(), $editMessage);

            return;
        }

        $this->markDoneTask($bot, $task, $editMessage);
    }

    private function markDoneTask(Nutgram $bot, Task $task, bool $editMessage): void
    {
        $em = $this->doctrine->getManager();

        if (!$em->contains($task)) {
            $task = $em->find(Task::class, $task->getId());
            if ($task === null) {
                $this->reply($bot, 'Задача не найдена.', $editMessage);

                return;
            }
        }

        $wasBlockingBefore = [];
        foreach ($task->getBlockedTasks() as $downstream) {
            if ($downstream->isBlocked()) {
                $wasBlockingBefore[$downstream->getId()->toRfc4122()] = $downstream;
            }
        }

        $task->markDone();
        $em->flush();

        $lines = ["✅ Задача выполнена: {$task->getTitle()}"];

        $unblocked = [];
        foreach ($wasBlockingBefore as $downstream) {
            if (!$downstream->isBlocked()) {
                $unblocked[] = $downstream;
            }
        }

        if ($unblocked !== []) {
            $lines[] = '';
            $lines[] = '🔓 Разблокирована:';
            foreach ($unblocked as $t) {
                $lines[] = "  • {$t->getTitle()}";
            }
        }

        $this->reply($bot, implode("\n", $lines), $editMessage);
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
