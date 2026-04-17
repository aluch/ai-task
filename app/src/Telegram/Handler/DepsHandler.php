<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TelegramUserResolver;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class DepsHandler
{
    private const MAX_BUTTONS = 8;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $shortId = trim(substr($text, 6)); // strip "/deps "
        if ($shortId === '') {
            $this->showInteractive($bot, $user);

            return;
        }

        $this->showDeps($bot, $user, $shortId, editMessage: false);
    }

    public function showDeps(Nutgram $bot, \App\Entity\User $user, string $shortId, bool $editMessage): void
    {
        $matches = $this->findByShortId($shortId, $user);
        if ($matches === []) {
            $this->reply($bot, "Задача {$shortId}… не найдена.", $editMessage);

            return;
        }

        if (count($matches) > 1) {
            $this->reply($bot, 'ID неоднозначен, уточни (больше символов).', $editMessage);

            return;
        }

        $task = $matches[0];
        $lines = ["📋 {$task->getTitle()}", ''];

        $blockers = $task->getBlockedBy()->toArray();
        $lines[] = '⛔ Заблокирована:';
        if ($blockers === []) {
            $lines[] = '  (ничего)';
        } else {
            foreach ($blockers as $b) {
                $sid = substr($b->getId()->toRfc4122(), 0, 8);
                $lines[] = "  • {$b->getTitle()} ({$b->getStatus()->value}) — {$sid}";
            }
        }

        $lines[] = '';

        $blocked = $task->getBlockedTasks()->toArray();
        $lines[] = '🔓 Блокирует:';
        if ($blocked === []) {
            $lines[] = '  (ничего)';
        } else {
            foreach ($blocked as $b) {
                $sid = substr($b->getId()->toRfc4122(), 0, 8);
                $lines[] = "  • {$b->getTitle()} ({$b->getStatus()->value}) — {$sid}";
            }
        }

        $this->reply($bot, implode("\n", $lines), $editMessage);
    }

    private function showInteractive(Nutgram $bot, \App\Entity\User $user): void
    {
        $tasks = $this->tasks->findForUser($user, limit: self::MAX_BUTTONS + 1);

        if ($tasks === []) {
            $bot->sendMessage(text: 'Нет открытых задач.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $shown = 0;
        foreach ($tasks as $task) {
            if ($shown >= self::MAX_BUTTONS) {
                break;
            }
            $shortId = substr($task->getId()->toRfc4122(), 0, 8);
            $label = mb_substr($task->getTitle(), 0, 30);
            if (mb_strlen($task->getTitle()) > 30) {
                $label .= '…';
            }
            $keyboard->addRow(
                InlineKeyboardButton::make(text: $label, callback_data: "deps:{$shortId}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Зависимости какой задачи посмотреть?',
            reply_markup: $keyboard,
        );
    }

    private function reply(Nutgram $bot, string $text, bool $edit): void
    {
        if ($edit) {
            $bot->editMessageText(text: $text, reply_markup: null);
        } else {
            $bot->sendMessage(text: $text);
        }
    }

    /**
     * @return Task[]
     */
    private function findByShortId(string $prefix, \App\Entity\User $user): array
    {
        $all = $this->tasks->findBy(['user' => $user]);

        return array_values(array_filter(
            $all,
            fn (Task $t) => str_starts_with($t->getId()->toRfc4122(), $prefix),
        ));
    }
}
