<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Exception\CyclicDependencyException;
use App\Repository\TaskRepository;
use App\Service\DependencyValidator;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;

class BlockHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly DependencyValidator $depValidator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $args = trim(substr($text, 7)); // strip "/block "
        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2) {
            $bot->sendMessage(text: "Использование: /block <task_id> <blocker_id>\nЗадача task_id будет заблокирована задачей blocker_id.");

            return;
        }

        [$blockedShort, $blockerShort] = $parts;

        $blockedMatches = $this->findByShortId($blockedShort, $user);
        $blockerMatches = $this->findByShortId($blockerShort, $user);

        if ($blockedMatches === []) {
            $bot->sendMessage(text: "Задача {$blockedShort}… не найдена.");

            return;
        }

        if ($blockerMatches === []) {
            $bot->sendMessage(text: "Задача {$blockerShort}… не найдена.");

            return;
        }

        if (count($blockedMatches) > 1 || count($blockerMatches) > 1) {
            $bot->sendMessage(text: 'Один из ID неоднозначен, уточни (больше символов).');

            return;
        }

        $blocked = $blockedMatches[0];
        $blocker = $blockerMatches[0];

        if ($blocked->getId()->equals($blocker->getId())) {
            $bot->sendMessage(text: '❌ Задача не может блокировать саму себя.');

            return;
        }

        if ($blocked->getBlockedBy()->contains($blocker)) {
            $bot->sendMessage(text: '🔗 Связь уже существует.');

            return;
        }

        try {
            $this->depValidator->validateNoCycle($blocked, $blocker);
        } catch (CyclicDependencyException $e) {
            $bot->sendMessage(text: '❌ ' . $e->getMessage());

            return;
        }

        $blocked->addBlocker($blocker);
        $this->em->flush();

        $bot->sendMessage(text: implode("\n", [
            '🔗 Связь создана',
            "⛔ {$blocked->getTitle()}",
            '⬅️ заблокирована',
            "✅ {$blocker->getTitle()}",
        ]));
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
