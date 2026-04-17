<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Exception\TaskIdException;
use App\Repository\TaskRepository;
use App\Service\RelativeTimeParser;
use App\Service\TaskIdResolver;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class SnoozeHandler
{
    private const MAX_BUTTONS = 8;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly TaskIdResolver $idResolver,
        private readonly ManagerRegistry $doctrine,
        private readonly RelativeTimeParser $timeParser,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $cmdArgs = trim(substr($text, 8)); // strip "/snooze"
        if ($cmdArgs === '') {
            $this->showInteractive($bot, $user);

            return;
        }

        $spacePos = strpos($cmdArgs, ' ');
        if ($spacePos === false) {
            $bot->sendMessage(text: "Использование: /snooze <uuid> <когда>\nИли /snooze без аргументов для интерактивного выбора.");

            return;
        }

        $uuidOrPrefix = substr($cmdArgs, 0, $spacePos);
        $rawUntil = trim(substr($cmdArgs, $spacePos + 1));

        try {
            $task = $this->idResolver->resolve($uuidOrPrefix, $user);
        } catch (TaskIdException $e) {
            $bot->sendMessage(text: $e->getMessage());

            return;
        }

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

        $em = $this->doctrine->getManager();
        if (!$em->contains($task)) {
            $task = $em->find(\App\Entity\Task::class, $task->getId());
            if ($task === null) {
                $bot->sendMessage(text: 'Задача не найдена.');

                return;
            }
        }

        $task->snooze($until);
        $em->flush();

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
            $uuid = $task->getId()->toRfc4122();
            $label = $this->truncate($task->getTitle(), 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(text: $label, callback_data: "snz:s1:{$uuid}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Какую задачу отложить?',
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
}
