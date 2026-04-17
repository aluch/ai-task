<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use App\Service\TaskIdResolver;
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
        private readonly TaskIdResolver $idResolver,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $arg = trim(substr($text, 6)); // strip "/deps"
        if ($arg === '') {
            $this->showInteractive($bot, $user);

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

    public function showDepsById(Nutgram $bot, \App\Entity\User $user, string $uuidOrPrefix, bool $editMessage): void
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
            $uuid = $task->getId()->toRfc4122();
            $label = $this->truncate($task->getTitle(), 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(text: $label, callback_data: "deps:{$uuid}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Зависимости какой задачи посмотреть?',
            reply_markup: $keyboard,
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
