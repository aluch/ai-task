<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Task;
use App\Enum\TaskPriority;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Отправляет пользователю напоминание о приближающемся дедлайне задачи.
 * Учитывает quiet hours и недавнюю активность — пропускает отправку
 * в этих случаях без пометки sent, чтобы Scheduler попробовал снова
 * при следующем тике.
 */
class ReminderSender
{
    public function __construct(
        private readonly TelegramNotifier $notifier,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendDeadlineReminder(Task $task): SendResult
    {
        $user = $task->getUser();
        $chatId = $user->getTelegramId();
        if ($chatId === null || $chatId === '') {
            return SendResult::SKIPPED_NO_CHAT_ID;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($user->isQuietHoursNow($now)) {
            $this->logger->info('Reminder skipped (quiet hours)', [
                'task_id' => $task->getId()->toRfc4122(),
                'user_id' => $user->getId()->toRfc4122(),
            ]);

            return SendResult::SKIPPED_QUIET_HOURS;
        }

        if ($user->isRecentlyActive($now)) {
            $this->logger->info('Reminder skipped (user recently active)', [
                'task_id' => $task->getId()->toRfc4122(),
                'user_id' => $user->getId()->toRfc4122(),
            ]);

            return SendResult::SKIPPED_RECENTLY_ACTIVE;
        }

        $text = $this->formatText($task, $now);
        $keyboard = $this->buildKeyboard($task->getId()->toRfc4122());

        $ok = $this->notifier->sendMessage(
            chatId: (int) $chatId,
            text: $text,
            replyMarkup: $keyboard,
        );

        if (!$ok) {
            return SendResult::FAILED;
        }

        // Помечаем «отправлено» через свежий EM (long-running процесс,
        // см. правило в CLAUDE.md о ManagerRegistry).
        $em = $this->doctrine->getManager();
        if (!$em->contains($task)) {
            $task = $em->find(Task::class, $task->getId());
            if ($task === null) {
                return SendResult::SENT; // отправили — но что-то с persist'ом
            }
        }
        $task->markDeadlineReminderSent();
        $em->flush();

        $this->logger->info('Reminder sent', [
            'task_id' => $task->getId()->toRfc4122(),
            'deadline' => $task->getDeadline()?->format('c'),
        ]);

        return SendResult::SENT;
    }

    private function formatText(Task $task, \DateTimeImmutable $now): string
    {
        $user = $task->getUser();
        $userTz = new \DateTimeZone($user->getTimezone());
        $deadline = $task->getDeadline();

        $timeLeft = $deadline !== null
            ? $this->formatTimeLeft($deadline, $now)
            : 'скоро';

        $deadlineLocal = $deadline?->setTimezone($userTz)->format('d.m H:i') ?? '—';

        $priEmoji = match ($task->getPriority()) {
            TaskPriority::URGENT => '🔴 urgent',
            TaskPriority::HIGH => '🔥 high',
            default => '',
        };

        $lines = ["⏰ {$timeLeft} дедлайн:", ''];
        $lines[] = "📝 {$task->getTitle()}";
        if ($priEmoji !== '') {
            $lines[] = $priEmoji;
        }
        $lines[] = "⏰ {$deadlineLocal} ({$user->getTimezone()})";

        return implode("\n", $lines);
    }

    private function formatTimeLeft(\DateTimeImmutable $deadline, \DateTimeImmutable $now): string
    {
        $diffSeconds = $deadline->getTimestamp() - $now->getTimestamp();

        if ($diffSeconds < 0) {
            $lateMinutes = (int) floor(-$diffSeconds / 60);

            return "уже просрочено на {$lateMinutes} мин —";
        }

        $minutes = (int) floor($diffSeconds / 60);
        if ($minutes < 60) {
            return "через {$minutes} мин";
        }

        $hours = (int) floor($minutes / 60);
        $rem = $minutes % 60;
        if ($rem === 0) {
            return "через {$hours} ч";
        }

        return "через {$hours} ч {$rem} мин";
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function buildKeyboard(string $taskUuid): array
    {
        return [
            [
                ['text' => '✅ Сделал', 'callback_data' => "rem:done:{$taskUuid}"],
            ],
            [
                ['text' => '⏸ Отложить на час', 'callback_data' => "rem:snooze1h:{$taskUuid}"],
            ],
            [
                ['text' => '🚀 Беру в работу', 'callback_data' => "rem:start:{$taskUuid}"],
            ],
        ];
    }
}
