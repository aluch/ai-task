<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Entity\User;
use App\Exception\CyclicDependencyException;
use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use App\Service\DependencyValidator;
use App\Service\PaginationStore;
use App\Service\TaskIdResolver;
use App\Service\TelegramUserResolver;
use App\Telegram\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SergiX44\Nutgram\Nutgram;

class BlockHandler
{
    public const PAGE_SIZE = 5;
    public const ACTION = 'block';
    public const MENU_PREFIX = 'dep:s1:m';

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly DependencyValidator $depValidator,
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

        $cmdArgs = trim(substr($text, 7)); // strip "/block"
        if ($cmdArgs === '') {
            $this->renderFirstPage($bot, $user);

            return;
        }

        $parts = preg_split('/\s+/', $cmdArgs, 2);
        if (count($parts) < 2) {
            $bot->sendMessage(text: "Использование: /block <task_uuid> <blocker_uuid>\nИли /block без аргументов — интерактивный выбор.");

            return;
        }

        [$blockedArg, $blockerArg] = $parts;
        $this->createLink($bot, $user, $blockedArg, $blockerArg, editMessage: false);
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
            ? 'Какую задачу нужно заблокировать?'
            : "Результаты по «{$search}»:";

        $keyboard = $this->paginator->buildTaskPickerKeyboard(
            tasks: $tasks,
            labelBuilder: fn (Task $t) => $this->truncate($t->getTitle(), 30),
            selectCallbackBuilder: fn (Task $t) => 'dep:s1:' . $t->getId()->toRfc4122(),
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

    public function createLink(
        Nutgram $bot,
        User $user,
        string $blockedIdOrPrefix,
        string $blockerIdOrPrefix,
        bool $editMessage = false,
    ): void {
        try {
            $blocked = $this->idResolver->resolve($blockedIdOrPrefix, $user);
        } catch (TaskIdException $e) {
            $this->reply($bot, "Блокируемая: {$e->getMessage()}", $editMessage);

            return;
        }

        try {
            $blocker = $this->idResolver->resolve($blockerIdOrPrefix, $user);
        } catch (TaskIdException $e) {
            $this->reply($bot, "Блокер: {$e->getMessage()}", $editMessage);

            return;
        }

        if ($blocked->getId()->equals($blocker->getId())) {
            $this->reply($bot, '❌ Задача не может блокировать саму себя.', $editMessage);

            return;
        }

        if ($blocked->getBlockedBy()->contains($blocker)) {
            $this->reply($bot, '🔗 Связь уже существует.', $editMessage);

            return;
        }

        try {
            $this->depValidator->validateNoCycle($blocked, $blocker);
        } catch (CyclicDependencyException $e) {
            $this->reply($bot, '❌ ' . $e->getMessage(), $editMessage);

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

        $blocked->addBlocker($blocker);
        $em->flush();

        $this->reply($bot, implode("\n", [
            '🔗 Связь создана',
            "⛔ {$blocked->getTitle()}",
            '⬅️ заблокирована',
            "✅ {$blocker->getTitle()}",
        ]), $editMessage);
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
