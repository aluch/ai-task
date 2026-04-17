<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use App\Service\TaskIdResolver;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class UnblockHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly TaskIdResolver $idResolver,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $cmdArgs = trim(substr($text, 9)); // strip "/unblock"
        if ($cmdArgs === '') {
            $this->startInteractiveFlow($bot, $user);

            return;
        }

        $parts = preg_split('/\s+/', $cmdArgs, 2);
        if (count($parts) < 2) {
            $bot->sendMessage(text: "Использование: /unblock <task_uuid> <blocker_uuid>\nИли /unblock без аргументов для интерактивного выбора.");

            return;
        }

        [$blockedArg, $blockerArg] = $parts;
        $this->removeLink($bot, $user, $blockedArg, $blockerArg);
    }

    public function removeLink(
        Nutgram $bot,
        \App\Entity\User $user,
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

        if (!$blocked->getBlockedBy()->contains($blocker)) {
            $this->reply($bot, 'Связи между этими задачами нет.', $editMessage);

            return;
        }

        $blocked->removeBlocker($blocker);
        $this->doctrine->getManager()->flush();

        $this->reply(
            $bot,
            "🔓 Связь убрана: «{$blocked->getTitle()}» больше не зависит от «{$blocker->getTitle()}».",
            $editMessage,
        );
    }

    private function startInteractiveFlow(Nutgram $bot, \App\Entity\User $user): void
    {
        $tasks = $this->tasks->findForUser($user, limit: 50);

        $blockedTasks = array_filter($tasks, fn (Task $t) => $t->getBlockedBy()->count() > 0);

        if ($blockedTasks === []) {
            $bot->sendMessage(text: 'Нет задач с блокерами.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $shown = 0;
        foreach ($blockedTasks as $task) {
            if ($shown >= 8) {
                break;
            }
            $uuid = $task->getId()->toRfc4122();
            $label = $this->truncate($task->getTitle(), 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(text: "⛔ {$label}", callback_data: "dep:u1:{$uuid}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'У какой задачи убрать блокер?',
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
