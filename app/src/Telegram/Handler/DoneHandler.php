<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class DoneHandler
{
    private const MAX_BUTTONS = 8;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $shortId = trim(substr($text, 6)); // strip "/done "
        if ($shortId === '') {
            $this->showInteractive($bot, $user);

            return;
        }

        $this->markDone($bot, $user, $shortId, editMessage: false);
    }

    public function markDone(Nutgram $bot, \App\Entity\User $user, string $shortId, bool $editMessage): void
    {
        $matches = $this->findByShortId($shortId, $user);

        if ($matches === []) {
            $this->reply($bot, "Задача с ID {$shortId}… не найдена среди твоих задач.", $editMessage);

            return;
        }

        if (count($matches) > 1) {
            $lines = ["Найдено несколько задач по ID {$shortId}…:"];
            foreach ($matches as $t) {
                $lines[] = '  ' . substr($t->getId()->toRfc4122(), 0, 13) . '… — ' . $t->getTitle();
            }
            $lines[] = '';
            $lines[] = 'Уточни ID (больше символов).';
            $this->reply($bot, implode("\n", $lines), $editMessage);

            return;
        }

        $task = $matches[0];

        $wasBlockingBefore = [];
        foreach ($task->getBlockedTasks() as $downstream) {
            if ($downstream->isBlocked()) {
                $wasBlockingBefore[$downstream->getId()->toRfc4122()] = $downstream;
            }
        }

        $task->markDone();
        $this->em->flush();

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
                $sid = substr($t->getId()->toRfc4122(), 0, 8);
                $lines[] = "  • {$t->getTitle()} — {$sid}";
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
            $shortId = substr($task->getId()->toRfc4122(), 0, 8);
            $label = mb_substr($task->getTitle(), 0, 30);
            if (mb_strlen($task->getTitle()) > 30) {
                $label .= '…';
            }
            $keyboard->addRow(
                InlineKeyboardButton::make(text: $label, callback_data: "done:{$shortId}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Какую задачу выполнил?',
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
