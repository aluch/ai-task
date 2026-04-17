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

class DoneHandler
{
    private const MAX_BUTTONS = 8;

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

        $arg = trim(substr($text, 6)); // strip "/done"
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

        $this->markDoneTask($bot, $task, editMessage: false);
    }

    public function markDoneById(Nutgram $bot, \App\Entity\User $user, string $uuidOrPrefix, bool $editMessage): void
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

        // Если task пришёл из stale EM — подмерджим в текущий, чтобы flush
        // действительно записал изменения.
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

    private function showInteractive(Nutgram $bot, \App\Entity\User $user): void
    {
        $tasks = $this->tasks->findUnblockedForUser($user);

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
                InlineKeyboardButton::make(text: $label, callback_data: "done:{$uuid}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Какую задачу выполнил?',
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
