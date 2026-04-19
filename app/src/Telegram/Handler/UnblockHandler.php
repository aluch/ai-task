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

class UnblockHandler
{
    public const PAGE_SIZE = 5;
    public const ACTION = 'unblock';
    public const MENU_PREFIX = 'dep:u1:m';

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

        $cmdArgs = trim(substr($text, 9)); // strip "/unblock"
        if ($cmdArgs === '') {
            $this->renderFirstPage($bot, $user);

            return;
        }

        $parts = preg_split('/\s+/', $cmdArgs, 2);
        if (count($parts) < 2) {
            $bot->sendMessage(text: "Использование: /unblock <task_uuid> <blocker_uuid>\nИли /unblock без аргументов — интерактивный выбор.");

            return;
        }

        [$blockedArg, $blockerArg] = $parts;
        $this->removeLink($bot, $user, $blockedArg, $blockerArg);
    }

    public function renderFirstPage(
        Nutgram $bot,
        User $user,
        string $search = '',
        ?int $editMessageId = null,
    ): void {
        // Показываем только задачи у которых есть блокеры — фильтрация в PHP
        // после выборки, чтобы не городить сложный SQL с JOIN.
        $all = $this->tasks->findForUserPaginated($user, null, 500, 0, $search);
        $withBlockers = array_values(array_filter($all, fn (Task $t) => $t->getBlockedBy()->count() > 0));
        $total = count($withBlockers);

        if ($total === 0) {
            $msg = $search === ''
                ? 'Нет задач с блокерами.'
                : "По запросу «{$search}» с блокерами ничего.";
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

        $all = $this->tasks->findForUserPaginated($user, null, 500, 0, $search);
        $withBlockers = array_values(array_filter($all, fn (Task $t) => $t->getBlockedBy()->count() > 0));
        $tasks = array_slice($withBlockers, $offset, self::PAGE_SIZE);

        $header = $search === ''
            ? 'У какой задачи убрать блокер?'
            : "Результаты по «{$search}»:";

        $keyboard = $this->paginator->buildTaskPickerKeyboard(
            tasks: $tasks,
            labelBuilder: fn (Task $t) => '⛔ ' . $this->truncate($t->getTitle(), 28),
            selectCallbackBuilder: fn (Task $t) => 'dep:u1:' . $t->getId()->toRfc4122(),
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

    public function removeLink(
        Nutgram $bot,
        User $user,
        string $blockedIdOrPrefix,
        string $blockerIdOrPrefix,
        bool $editMessage = false,
    ): void {
        try {
            $blocked = $this->idResolver->resolve($blockedIdOrPrefix, $user);
            $blocker = $this->idResolver->resolve($blockerIdOrPrefix, $user);
        } catch (TaskIdException $e) {
            $this->reply($bot, $e->getMessage(), $editMessage);

            return;
        }

        $em = $this->doctrine->getManager();
        if (!$em->contains($blocked)) {
            $blocked = $em->find(Task::class, $blocked->getId());
        }
        if (!$em->contains($blocker)) {
            $blocker = $em->find(Task::class, $blocker->getId());
        }
        if ($blocked === null || $blocker === null) {
            $this->reply($bot, 'Одна из задач недоступна, попробуй ещё раз.', $editMessage);

            return;
        }

        if (!$blocked->getBlockedBy()->contains($blocker)) {
            $this->reply($bot, 'Связи между этими задачами нет.', $editMessage);

            return;
        }

        $blocked->removeBlocker($blocker);
        $em->flush();

        $this->reply(
            $bot,
            "🔓 Связь убрана: «{$blocked->getTitle()}» больше не зависит от «{$blocker->getTitle()}».",
            $editMessage,
        );
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
