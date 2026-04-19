<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Entity\User;
use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use App\Service\PaginationStore;
use App\Service\RelativeTimeParser;
use App\Service\TaskIdResolver;
use App\Service\TelegramUserResolver;
use App\Telegram\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SergiX44\Nutgram\Nutgram;

class SnoozeHandler
{
    public const PAGE_SIZE = 5;
    public const ACTION = 'snooze';
    public const MENU_PREFIX = 'snz:m';

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly TaskIdResolver $idResolver,
        private readonly ManagerRegistry $doctrine,
        private readonly RelativeTimeParser $timeParser,
        private readonly PaginationStore $paginationStore,
        private readonly Paginator $paginator,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $cmdArgs = trim(substr($text, 8)); // strip "/snooze"
        if ($cmdArgs === '') {
            $this->renderFirstPage($bot, $user);

            return;
        }

        $spacePos = strpos($cmdArgs, ' ');
        if ($spacePos === false) {
            $bot->sendMessage(text: "Использование: /snooze <uuid> <когда>\nИли /snooze без аргументов — интерактивный выбор.");

            return;
        }

        $uuidOrPrefix = substr($cmdArgs, 0, $spacePos);
        $rawUntil = trim(substr($cmdArgs, $spacePos + 1));

        try {
            $task = $this->idResolver->resolve($uuidOrPrefix, $user);
        } catch (TaskIdException $e) {
            $bot->sendMessage(text: $e->getMessage());

            return;
        }

        $userTz = new \DateTimeZone($user->getTimezone());
        $until = $this->timeParser->parse($rawUntil, $userTz);
        if ($until === null) {
            $bot->sendMessage(text: "Не могу распознать время: {$rawUntil}\nПримеры: +2h, +1d, tomorrow 09:00, 2026-04-20 18:00");

            return;
        }

        if ($until <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $bot->sendMessage(text: 'Время должно быть в будущем.');

            return;
        }

        $em = $this->doctrine->getManager();
        if (!$em->contains($task)) {
            $task = $em->find(Task::class, $task->getId());
            if ($task === null) {
                $bot->sendMessage(text: 'Задача не найдена.');

                return;
            }
        }

        $task->snooze($until);
        $em->flush();

        $localUntil = $until->setTimezone($userTz);
        $bot->sendMessage(
            text: "💤 Задача отложена: {$task->getTitle()}\nДо: {$localUntil->format('d.m H:i')} ({$user->getTimezone()})",
        );
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
            ? 'Какую задачу отложить?'
            : "Результаты по «{$search}»:";

        $keyboard = $this->paginator->buildTaskPickerKeyboard(
            tasks: $tasks,
            labelBuilder: fn (Task $t) => $this->truncate($t->getTitle(), 30),
            selectCallbackBuilder: fn (Task $t) => 'snz:s1:' . $t->getId()->toRfc4122(),
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

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . '…';
    }
}
