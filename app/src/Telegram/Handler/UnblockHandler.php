<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;

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

        $args = trim(substr($text, 9)); // strip "/unblock "
        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2) {
            $bot->sendMessage(text: 'Использование: /unblock <task_id> <blocker_id>');

            return;
        }

        [$blockedShort, $blockerShort] = $parts;

        $blockedMatches = $this->findByShortId($blockedShort, $user);
        $blockerMatches = $this->findByShortId($blockerShort, $user);

        if ($blockedMatches === [] || $blockerMatches === []) {
            $bot->sendMessage(text: 'Одна из задач не найдена.');

            return;
        }

        if (count($blockedMatches) > 1 || count($blockerMatches) > 1) {
            $bot->sendMessage(text: 'Один из ID неоднозначен, уточни (больше символов).');

            return;
        }

        $blocked = $blockedMatches[0];
        $blocker = $blockerMatches[0];

        if (!$blocked->getBlockedBy()->contains($blocker)) {
            $bot->sendMessage(text: 'Связи между этими задачами нет.');

            return;
        }

        $blocked->removeBlocker($blocker);
        $this->em->flush();

        $bot->sendMessage(text: "🔓 Связь убрана: «{$blocked->getTitle()}» больше не зависит от «{$blocker->getTitle()}».");
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
