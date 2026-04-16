<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TelegramUserResolver;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class DependencyCallbackHandler
{
    private const MAX_BUTTONS = 8;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly BlockHandler $blockHandler,
        private readonly UnblockHandler $unblockHandler,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $bot->answerCallbackQuery();

        $user = $this->userResolver->resolve($bot);

        $parts = explode(':', $data);
        if (count($parts) < 3 || $parts[0] !== 'dep') {
            return;
        }

        $step = $parts[1];

        match ($step) {
            's1' => $this->handleBlockStep1($bot, $user, $parts[2]),
            's2' => $this->handleBlockStep2($bot, $user, $parts[2], $parts[3] ?? ''),
            'u1' => $this->handleUnblockStep1($bot, $user, $parts[2]),
            'u2' => $this->handleUnblockStep2($bot, $user, $parts[2], $parts[3] ?? ''),
            default => null,
        };
    }

    /**
     * Пользователь выбрал задачу для блокировки → показать блокеры.
     */
    private function handleBlockStep1(Nutgram $bot, \App\Entity\User $user, string $blockedShort): void
    {
        $blocked = $this->findOne($blockedShort, $user);
        if ($blocked === null) {
            $bot->editMessageText(text: "Задача {$blockedShort}… не найдена.");

            return;
        }

        $tasks = $this->tasks->findForUser($user, limit: self::MAX_BUTTONS + 1);

        $keyboard = InlineKeyboardMarkup::make();
        $shown = 0;
        foreach ($tasks as $task) {
            if ($shown >= self::MAX_BUTTONS) {
                break;
            }
            if ($task->getId()->equals($blocked->getId())) {
                continue;
            }
            $shortId = substr($task->getId()->toRfc4122(), 0, 8);
            $label = mb_substr($task->getTitle(), 0, 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $label,
                    callback_data: "dep:s2:{$blockedShort}:{$shortId}",
                ),
            );
            $shown++;
        }

        $title = $blocked->getTitle();
        $bot->editMessageText(
            text: "⛔ {$title}\n⬅️ заблокирована чем?",
            reply_markup: $keyboard,
        );
    }

    /**
     * Пользователь выбрал блокер → создать связь.
     */
    private function handleBlockStep2(
        Nutgram $bot,
        \App\Entity\User $user,
        string $blockedShort,
        string $blockerShort,
    ): void {
        $this->blockHandler->createLink($bot, $user, $blockedShort, $blockerShort, editMessage: true);
    }

    /**
     * Пользователь выбрал заблокированную задачу → показать её блокеры.
     */
    private function handleUnblockStep1(Nutgram $bot, \App\Entity\User $user, string $blockedShort): void
    {
        $blocked = $this->findOne($blockedShort, $user);
        if ($blocked === null) {
            $bot->editMessageText(text: "Задача {$blockedShort}… не найдена.");

            return;
        }

        $blockers = $blocked->getBlockedBy()->toArray();
        if ($blockers === []) {
            $bot->editMessageText(text: "У задачи «{$blocked->getTitle()}» нет блокеров.");

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($blockers as $blocker) {
            $shortId = substr($blocker->getId()->toRfc4122(), 0, 8);
            $label = mb_substr($blocker->getTitle(), 0, 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $label,
                    callback_data: "dep:u2:{$blockedShort}:{$shortId}",
                ),
            );
        }

        $bot->editMessageText(
            text: "🔓 Убрать блокер у «{$blocked->getTitle()}»:",
            reply_markup: $keyboard,
        );
    }

    /**
     * Пользователь выбрал блокер для удаления → убрать связь.
     */
    private function handleUnblockStep2(
        Nutgram $bot,
        \App\Entity\User $user,
        string $blockedShort,
        string $blockerShort,
    ): void {
        $this->unblockHandler->removeLink($bot, $user, $blockedShort, $blockerShort, editMessage: true);
    }

    private function findOne(string $shortId, \App\Entity\User $user): ?Task
    {
        $all = $this->tasks->findBy(['user' => $user]);
        $matches = array_values(array_filter(
            $all,
            fn (Task $t) => str_starts_with($t->getId()->toRfc4122(), $shortId),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }
}
