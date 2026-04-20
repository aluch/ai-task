<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Uid\Uuid;

/**
 * Обрабатывает кнопки под напоминанием о дедлайне:
 *   rem:done:<uuid>      — пометить выполненной
 *   rem:snooze1h:<uuid>  — отложить на час + сбросить deadline_reminder_sent_at
 *                          чтобы после разбуживания напоминание пришло снова
 *                          (если дедлайн ещё не прошёл)
 *   rem:start:<uuid>     — перевести в IN_PROGRESS
 */
class ReminderCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $bot->answerCallbackQuery();

        $parts = explode(':', $data);
        if (count($parts) < 3 || $parts[0] !== 'rem') {
            return;
        }

        $action = $parts[1];
        $uuid = $parts[2];

        $user = $this->userResolver->resolve($bot);
        $task = $this->findOwned($uuid, $user);
        if ($task === null) {
            $bot->editMessageText(text: 'Задача не найдена.', reply_markup: null);

            return;
        }

        match ($action) {
            'done' => $this->handleDone($bot, $task),
            'snooze1h' => $this->handleSnooze1h($bot, $task),
            'start' => $this->handleStart($bot, $task),
            default => null,
        };
    }

    private function handleDone(Nutgram $bot, Task $task): void
    {
        $em = $this->doctrine->getManager();
        $task->markDone();
        $em->flush();

        $this->logger->info('Reminder: task marked done', [
            'task_id' => $task->getId()->toRfc4122(),
        ]);

        $bot->editMessageText(
            text: "✅ Сделано! Задача закрыта: {$task->getTitle()}",
            reply_markup: null,
        );
    }

    private function handleSnooze1h(Nutgram $bot, Task $task): void
    {
        $em = $this->doctrine->getManager();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = $now->modify('+1 hour');

        $task->snooze($until);
        // Сбрасываем reminder sent — если после разбуживания дедлайн ещё
        // не прошёл, напоминание придёт снова.
        $task->setDeadlineReminderSentAt(null);
        $em->flush();

        $this->logger->info('Reminder: task snoozed 1h', [
            'task_id' => $task->getId()->toRfc4122(),
        ]);

        $userTz = new \DateTimeZone($task->getUser()->getTimezone());
        $localUntil = $until->setTimezone($userTz)->format('H:i');

        $bot->editMessageText(
            text: "⏸ Отложил на час (до {$localUntil}).",
            reply_markup: null,
        );
    }

    private function handleStart(Nutgram $bot, Task $task): void
    {
        $em = $this->doctrine->getManager();
        $task->setStatus(TaskStatus::IN_PROGRESS);
        $em->flush();

        $this->logger->info('Reminder: task in progress', [
            'task_id' => $task->getId()->toRfc4122(),
        ]);

        $bot->editMessageText(
            text: "🚀 Взял в работу: {$task->getTitle()}\nКогда закончишь — /done или напиши мне.",
            reply_markup: null,
        );
    }

    private function findOwned(string $uuid, User $user): ?Task
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }
        $em = $this->doctrine->getManager();
        $task = $em->getRepository(Task::class)->find(Uuid::fromString($uuid));
        if ($task === null || !$task->getUser()->getId()->equals($user->getId())) {
            return null;
        }

        return $task;
    }
}
