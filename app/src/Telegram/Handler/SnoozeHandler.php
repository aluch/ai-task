<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\RelativeTimeParser;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class SnoozeHandler
{
    private const MAX_BUTTONS = 8;

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

        $cmdArgs = trim(substr($text, 8)); // strip "/snooze "
        $spacePos = strpos($cmdArgs, ' ');

        if ($cmdArgs === '' || $spacePos === false) {
            if ($cmdArgs !== '' && $spacePos === false) {
                // Только ID без времени
                $bot->sendMessage(text: "Использование: /snooze <id> <когда>\nИли /snooze без аргументов для интерактивного выбора.");

                return;
            }

            $this->showInteractive($bot, $user);

            return;
        }

        // С аргументами — старый режим
        $shortId = substr($cmdArgs, 0, $spacePos);
        $rawUntil = trim(substr($cmdArgs, $spacePos + 1));

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

    private function showInteractive(Nutgram $bot, \App\Entity\User $user): void
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
            $shortId = substr($task->getId()->toRfc4122(), 0, 8);
            $label = mb_substr($task->getTitle(), 0, 30);
            if (mb_strlen($task->getTitle()) > 30) {
                $label .= '…';
            }
            $keyboard->addRow(
                InlineKeyboardButton::make(text: $label, callback_data: "snz:s1:{$shortId}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Какую задачу отложить?',
            reply_markup: $keyboard,
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
