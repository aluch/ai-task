<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Task;
use App\Enum\TaskPriority;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Отправляет пользователю три типа уведомлений:
 *   - напоминание о приближающемся дедлайне (Тип А)
 *   - периодическое напоминание о залежавшейся задаче (Тип Б)
 *   - уведомление о разбуживании отложенной (SNOOZED) задачи (Тип В)
 *
 * Каждый тип учитывает quiet hours. Пропуск = не помечаем «отправлено»,
 * следующий тик Scheduler попробует снова.
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

        // Короткие напоминания (< 5 минут) — пользователь сам явно просил
        // напомнить через очень короткий промежуток. В такой ситуации фильтр
        // recently_active блокировал бы ровно то, что он заказал. Quiet hours
        // всё равно работают — если сейчас ночь, пользователь сам виноват.
        $remindBefore = $task->getRemindBeforeDeadlineMinutes();
        $skipRecencyFilter = $remindBefore !== null && $remindBefore < 5;

        if (!$skipRecencyFilter && $user->isRecentlyActive($now)) {
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

    public function sendPeriodicReminder(Task $task): SendResult
    {
        $user = $task->getUser();
        $chatId = $user->getTelegramId();
        if ($chatId === null || $chatId === '') {
            return SendResult::SKIPPED_NO_CHAT_ID;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($user->isQuietHoursNow($now)) {
            return SendResult::SKIPPED_QUIET_HOURS;
        }

        // Для периодических коротких исключений нет — пользователь
        // просил «напоминать каждые N», но не «немедленно даже если я
        // прямо сейчас пишу». Активный диалог = ждём паузы.
        if ($user->isRecentlyActive($now)) {
            return SendResult::SKIPPED_RECENTLY_ACTIVE;
        }

        $text = $this->formatPeriodicText($task, $now);
        $keyboard = $this->buildKeyboard($task->getId()->toRfc4122());

        $ok = $this->notifier->sendMessage(
            chatId: (int) $chatId,
            text: $text,
            replyMarkup: $keyboard,
        );

        if (!$ok) {
            return SendResult::FAILED;
        }

        $em = $this->doctrine->getManager();
        if (!$em->contains($task)) {
            $task = $em->find(Task::class, $task->getId());
            if ($task === null) {
                return SendResult::SENT;
            }
        }
        // Скользящее окно: от этого момента отсчитываем следующий интервал.
        $task->setLastRemindedAt($now);
        $em->flush();

        $this->logger->info('Periodic reminder sent', [
            'task_id' => $task->getId()->toRfc4122(),
            'interval_minutes' => $task->getReminderIntervalMinutes(),
        ]);

        return SendResult::SENT;
    }

    public function sendSnoozeWakeup(Task $task): SendResult
    {
        $user = $task->getUser();
        $chatId = $user->getTelegramId();
        if ($chatId === null || $chatId === '') {
            return SendResult::SKIPPED_NO_CHAT_ID;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Только quiet hours — разбуживание это событие, которое пользователь
        // сам запланировал, а не автоматический пинок. isRecentlyActive тут
        // не применяем: «я отложил до 10:00» значит «в 10:00 разбуди», а не
        // «в 10:00 разбуди если я минут 5 молчу».
        if ($user->isQuietHoursNow($now)) {
            return SendResult::SKIPPED_QUIET_HOURS;
        }

        $text = $this->formatSnoozeWakeupText($task);
        $keyboard = $this->buildKeyboard($task->getId()->toRfc4122());

        $ok = $this->notifier->sendMessage(
            chatId: (int) $chatId,
            text: $text,
            replyMarkup: $keyboard,
        );

        if (!$ok) {
            return SendResult::FAILED;
        }

        // Реактивируем задачу через свежий EM (см. правило CLAUDE.md).
        $em = $this->doctrine->getManager();
        if (!$em->contains($task)) {
            $task = $em->find(Task::class, $task->getId());
            if ($task === null) {
                return SendResult::SENT;
            }
        }
        $task->reactivate();
        $em->flush();

        $this->logger->info('Snooze wakeup sent', [
            'task_id' => $task->getId()->toRfc4122(),
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

    private function formatPeriodicText(Task $task, \DateTimeImmutable $now): string
    {
        $priEmoji = match ($task->getPriority()) {
            TaskPriority::URGENT => '🔴 urgent',
            TaskPriority::HIGH => '🔥 high',
            TaskPriority::LOW => '🔽 low',
            default => '',
        };

        $age = $this->formatAge($task->getCreatedAt(), $now);

        $lines = ['🔔 Напоминание о задаче:', ''];
        $lines[] = "📝 {$task->getTitle()}";
        if ($priEmoji !== '') {
            $lines[] = $priEmoji;
        }

        // На всякий случай — если у задачи всё-таки есть дедлайн (AI по
        // правилам не должен ставить interval при дедлайне, но вдруг).
        $deadline = $task->getDeadline();
        if ($deadline !== null) {
            $userTz = new \DateTimeZone($task->getUser()->getTimezone());
            $deadlineLocal = $deadline->setTimezone($userTz)->format('d.m H:i');
            $lines[] = "⏰ дедлайн {$deadlineLocal}";
        }

        $lines[] = '';
        $lines[] = "Висит уже {$age}.";

        return implode("\n", $lines);
    }

    private function formatSnoozeWakeupText(Task $task): string
    {
        $lines = ['🔔 Задача снова активна:', ''];
        $lines[] = "📝 {$task->getTitle()}";

        $deadline = $task->getDeadline();
        if ($deadline !== null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $lines[] = '⏰ дедлайн ' . $this->formatTimeLeft($deadline, $now);
        }

        $snoozedUntil = $task->getSnoozedUntil();
        if ($snoozedUntil !== null) {
            $userTz = new \DateTimeZone($task->getUser()->getTimezone());
            $local = $snoozedUntil->setTimezone($userTz);
            $lines[] = '';
            $lines[] = 'Ты откладывал до ' . $local->format('d.m H:i') . '.';
        }

        return implode("\n", $lines);
    }

    private function formatAge(\DateTimeImmutable $from, \DateTimeImmutable $now): string
    {
        $diffSeconds = max(0, $now->getTimestamp() - $from->getTimestamp());
        $minutes = (int) floor($diffSeconds / 60);

        if ($minutes < 60) {
            return $this->pluralize($minutes, 'минуту', 'минуты', 'минут');
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $this->pluralize($hours, 'час', 'часа', 'часов');
        }

        $days = (int) floor($hours / 24);

        return $this->pluralize($days, 'день', 'дня', 'дней');
    }

    private function pluralize(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return "{$n} {$many}";
        }
        if ($mod10 === 1) {
            return "{$n} {$one}";
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return "{$n} {$few}";
        }

        return "{$n} {$many}";
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
