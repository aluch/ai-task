<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\RelativeTimeParser;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;

class SnoozeHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly EntityManagerInterface $em,
        private readonly RelativeTimeParser $timeParser,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        // /snooze <id> <when>
        $args = trim(substr($text, 8)); // strip "/snooze "
        $spacePos = strpos($args, ' ');
        if ($args === '' || $spacePos === false) {
            $bot->sendMessage(text: "Использование: /snooze <id> <когда>\nПримеры: /snooze 019d9289 +2h\n/snooze 019d9289 tomorrow 09:00");

            return;
        }

        $shortId = substr($args, 0, $spacePos);
        $rawUntil = trim(substr($args, $spacePos + 1));

        $matches = $this->findByShortId($shortId, $user);

        if ($matches === []) {
            $bot->sendMessage(text: "Задача с ID {$shortId}… не найдена.");

            return;
        }

        if (count($matches) > 1) {
            $bot->sendMessage(text: "Найдено несколько задач по ID {$shortId}…, уточни ID.");

            return;
        }

        $task = $matches[0];
        $userTz = new \DateTimeZone($user->getTimezone());

        $until = $this->timeParser->parse($rawUntil, $userTz);
        if ($until === null) {
            $bot->sendMessage(text: "Не могу распознать время: {$rawUntil}\nПримеры: +2h, +1d, tomorrow 09:00, 2026-04-20 18:00");

            return;
        }

        if ($until <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $bot->sendMessage(text: 'Время должно быть в будущем.');

            return;
        }

        $task->snooze($until);
        $this->em->flush();

        $localUntil = $until->setTimezone($userTz);
        $bot->sendMessage(
            text: "💤 Задача отложена: {$task->getTitle()}\nДо: {$localUntil->format('d.m H:i')} ({$user->getTimezone()})",
        );
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
