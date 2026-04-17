<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Exception\CyclicDependencyException;
use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use App\Service\DependencyValidator;
use App\Service\TaskIdResolver;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BlockHandler
{
    private const MAX_BUTTONS = 8;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly DependencyValidator $depValidator,
        private readonly TaskIdResolver $idResolver,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $cmdArgs = trim(substr($text, 7)); // strip "/block"
        if ($cmdArgs === '') {
            $this->startInteractiveFlow($bot, $user);

            return;
        }

        $parts = preg_split('/\s+/', $cmdArgs, 2);
        if (count($parts) < 2) {
            $bot->sendMessage(text: "Использование: /block <task_uuid> <blocker_uuid>\nИли /block без аргументов для интерактивного выбора.");

            return;
        }

        [$blockedArg, $blockerArg] = $parts;
        $this->createLink($bot, $user, $blockedArg, $blockerArg, editMessage: false);
    }

    public function createLink(
        Nutgram $bot,
        \App\Entity\User $user,
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

        $blocked->addBlocker($blocker);
        $this->doctrine->getManager()->flush();

        $this->reply($bot, implode("\n", [
            '🔗 Связь создана',
            "⛔ {$blocked->getTitle()}",
            '⬅️ заблокирована',
            "✅ {$blocker->getTitle()}",
        ]), $editMessage);
    }

    private function startInteractiveFlow(Nutgram $bot, \App\Entity\User $user): void
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
                InlineKeyboardButton::make(text: $label, callback_data: "dep:s1:{$uuid}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Какую задачу нужно заблокировать?',
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
