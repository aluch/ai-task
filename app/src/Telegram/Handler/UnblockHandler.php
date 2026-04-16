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

class UnblockHandler
{
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

        $cmdArgs = trim(substr($text, 9)); // strip "/unblock "
        $parts = preg_split('/\s+/', $cmdArgs, 2);

        if ($cmdArgs === '' || count($parts) < 2) {
            if ($cmdArgs !== '' && count($parts) === 1) {
                $bot->sendMessage(text: "Использование: /unblock <task_id> <blocker_id>\nИли /unblock без аргументов для интерактивного выбора.");

                return;
            }

            $this->startInteractiveFlow($bot, $user);

            return;
        }

        [$blockedShort, $blockerShort] = $parts;
        $this->removeLink($bot, $user, $blockedShort, $blockerShort);
    }

    public function removeLink(
        Nutgram $bot,
        \App\Entity\User $user,
        string $blockedShort,
        string $blockerShort,
        bool $editMessage = false,
    ): void {
        $blockedMatches = $this->findByShortId($blockedShort, $user);
        $blockerMatches = $this->findByShortId($blockerShort, $user);

        if ($blockedMatches === [] || $blockerMatches === []) {
            $this->reply($bot, 'Одна из задач не найдена.', $editMessage);

            return;
        }

        if (count($blockedMatches) > 1 || count($blockerMatches) > 1) {
            $this->reply($bot, 'Один из ID неоднозначен.', $editMessage);

            return;
        }

        $blocked = $blockedMatches[0];
        $blocker = $blockerMatches[0];

        if (!$blocked->getBlockedBy()->contains($blocker)) {
            $this->reply($bot, 'Связи между этими задачами нет.', $editMessage);

            return;
        }

        $blocked->removeBlocker($blocker);
        $this->em->flush();

        $this->reply(
            $bot,
            "🔓 Связь убрана: «{$blocked->getTitle()}» больше не зависит от «{$blocker->getTitle()}».",
            $editMessage,
        );
    }

    private function startInteractiveFlow(Nutgram $bot, \App\Entity\User $user): void
    {
        $tasks = $this->tasks->findForUser($user, limit: 50);

        // Показываем только задачи, у которых есть блокеры
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
            $shortId = substr($task->getId()->toRfc4122(), 0, 8);
            $label = mb_substr($task->getTitle(), 0, 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(text: "⛔ {$label}", callback_data: "dep:u1:{$shortId}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'У какой задачи убрать блокер?',
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
