<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TelegramUserResolver;
use SergiX44\Nutgram\Nutgram;

class DepsHandler
{
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
            $bot->sendMessage(text: 'Использование: /deps <id>');

            return;
        }

        $matches = $this->findByShortId($shortId, $user);
        if ($matches === []) {
            $bot->sendMessage(text: "Задача {$shortId}… не найдена.");

            return;
        }

        if (count($matches) > 1) {
            $bot->sendMessage(text: 'ID неоднозначен, уточни (больше символов).');

            return;
        }

        $task = $matches[0];
        $lines = ["📋 {$task->getTitle()}", ''];

        // Blockers
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

        // Blocking
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

        $bot->sendMessage(text: implode("\n", $lines));
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
