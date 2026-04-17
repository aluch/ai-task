<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Service\RelativeTimeParser;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\Uid\Uuid;

class TaskActionCallbackHandler
{
    private const SNOOZE_PRESETS = [
        '30m' => '+30 minutes',
        '1h' => '+1 hours',
        '3h' => '+3 hours',
        '1w' => '+1 week',
    ];

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly ManagerRegistry $doctrine,
        private readonly RelativeTimeParser $timeParser,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $bot->answerCallbackQuery();

        $user = $this->userResolver->resolve($bot);

        $parts = explode(':', $data);
        $prefix = $parts[0] ?? '';

        match ($prefix) {
            'done' => $this->handleDone($bot, $user, $parts[1] ?? ''),
            'snz' => $this->handleSnooze($bot, $user, $parts),
            'deps' => $this->handleDeps($bot, $user, $parts[1] ?? ''),
            default => null,
        };
    }

    private function handleDone(Nutgram $bot, \App\Entity\User $user, string $uuid): void
    {
        $em = $this->doctrine->getManager();
        $task = $this->findByUuidFresh($em, $uuid, $user);
        if ($task === null) {
            $this->logger->warning('DoneCallback: task not found', ['uuid' => $uuid]);
            $bot->editMessageText(text: 'Задача не найдена.', reply_markup: null);

            return;
        }

        $this->logger->info('DoneCallback: marking done', [
            'task_id' => $task->getId()->toRfc4122(),
            'status_before' => $task->getStatus()->value,
        ]);

        $wasBlockingBefore = [];
        foreach ($task->getBlockedTasks() as $downstream) {
            if ($downstream->isBlocked()) {
                $wasBlockingBefore[$downstream->getId()->toRfc4122()] = $downstream;
            }
        }

        $task->markDone();
        $em->flush();

        $this->logger->info('DoneCallback: flushed', [
            'task_id' => $task->getId()->toRfc4122(),
            'status_after' => $task->getStatus()->value,
            'completed_at' => $task->getCompletedAt()?->format('c'),
        ]);

        $lines = ["✅ Задача выполнена: {$task->getTitle()}"];

        $unblocked = [];
        foreach ($wasBlockingBefore as $downstream) {
            if (!$downstream->isBlocked()) {
                $unblocked[] = $downstream;
            }
        }

        if ($unblocked !== []) {
            $lines[] = '';
            $lines[] = '🔓 Разблокирована:';
            foreach ($unblocked as $t) {
                $lines[] = "  • {$t->getTitle()}";
            }
        }

        $bot->editMessageText(text: implode("\n", $lines), reply_markup: null);
    }

    /**
     * @param string[] $parts
     */
    private function handleSnooze(Nutgram $bot, \App\Entity\User $user, array $parts): void
    {
        $step = $parts[1] ?? '';

        if ($step === 's1') {
            $this->snoozeStep1($bot, $user, $parts[2] ?? '');
        } elseif ($step === 's2') {
            $this->snoozeStep2($bot, $user, $parts[2] ?? '', $parts[3] ?? '');
        }
    }

    private function snoozeStep1(Nutgram $bot, \App\Entity\User $user, string $uuid): void
    {
        $em = $this->doctrine->getManager();
        $task = $this->findByUuidFresh($em, $uuid, $user);
        if ($task === null) {
            $bot->editMessageText(text: 'Задача не найдена.', reply_markup: null);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();

        $presets = [
            ['30 мин', "snz:s2:{$uuid}:30m"],
            ['1 час', "snz:s2:{$uuid}:1h"],
            ['3 часа', "snz:s2:{$uuid}:3h"],
            ['Завтра 9:00', "snz:s2:{$uuid}:tom9"],
            ['Завтра 18:00', "snz:s2:{$uuid}:tom18"],
            ['Через неделю', "snz:s2:{$uuid}:1w"],
        ];

        foreach ($presets as [$label, $cbData]) {
            $keyboard->addRow(
                InlineKeyboardButton::make(text: $label, callback_data: $cbData),
            );
        }

        $title = mb_substr($task->getTitle(), 0, 40);
        $bot->editMessageText(
            text: "⏸ Откладываю: {$title}\nНа сколько?",
            reply_markup: $keyboard,
        );
    }

    private function snoozeStep2(Nutgram $bot, \App\Entity\User $user, string $uuid, string $preset): void
    {
        $em = $this->doctrine->getManager();
        $task = $this->findByUuidFresh($em, $uuid, $user);
        if ($task === null) {
            $bot->editMessageText(text: 'Задача не найдена.', reply_markup: null);

            return;
        }

        $userTz = new \DateTimeZone($user->getTimezone());
        $utc = new \DateTimeZone('UTC');

        $until = match ($preset) {
            'tom9' => (new \DateTimeImmutable('tomorrow 09:00', $userTz))->setTimezone($utc),
            'tom18' => (new \DateTimeImmutable('tomorrow 18:00', $userTz))->setTimezone($utc),
            default => $this->timeParser->parse(
                self::SNOOZE_PRESETS[$preset] ?? '+1 hours',
                $userTz,
            ),
        };

        if ($until === null) {
            $bot->editMessageText(text: 'Не удалось рассчитать время.', reply_markup: null);

            return;
        }

        $this->logger->info('SnoozeCallback: snoozing', [
            'task_id' => $task->getId()->toRfc4122(),
            'until_utc' => $until->format('c'),
        ]);

        $task->snooze($until);
        $em->flush();

        $localUntil = $until->setTimezone($userTz);
        $bot->editMessageText(
            text: "💤 Задача отложена: {$task->getTitle()}\nДо: {$localUntil->format('d.m H:i')} ({$user->getTimezone()})",
            reply_markup: null,
        );
    }

    private function handleDeps(Nutgram $bot, \App\Entity\User $user, string $uuid): void
    {
        $em = $this->doctrine->getManager();
        $task = $this->findByUuidFresh($em, $uuid, $user);
        if ($task === null) {
            $bot->editMessageText(text: 'Задача не найдена.', reply_markup: null);

            return;
        }

        $lines = ["📋 {$task->getTitle()}", ''];

        $blockers = $task->getBlockedBy()->toArray();
        $lines[] = '⛔ Заблокирована:';
        if ($blockers === []) {
            $lines[] = '  (ничего)';
        } else {
            foreach ($blockers as $b) {
                $sid = $b->getId()->toRfc4122();
                $lines[] = "  • {$b->getTitle()} ({$b->getStatus()->value}) — {$sid}";
            }
        }

        $lines[] = '';

        $blocked = $task->getBlockedTasks()->toArray();
        $lines[] = '🔓 Блокирует:';
        if ($blocked === []) {
            $lines[] = '  (ничего)';
        } else {
            foreach ($blocked as $b) {
                $sid = $b->getId()->toRfc4122();
                $lines[] = "  • {$b->getTitle()} ({$b->getStatus()->value}) — {$sid}";
            }
        }

        $bot->editMessageText(text: implode("\n", $lines), reply_markup: null);
    }

    /**
     * Получаем repo ИЗ текущего EM (не через DI-инжект), чтобы сущность
     * попала в identity map ТОГО ЖЕ EM, куда потом идёт flush. После
     * resetManager() DI-инжектнутый TaskRepository держит stale EM
     * reference — находит сущность в старом EM, а наш flush по новому
     * не пишет ничего. Это корень бага с «выполнил но не сохранил».
     */
    private function findByUuidFresh(
        \Doctrine\ORM\EntityManagerInterface $em,
        string $uuid,
        \App\Entity\User $user,
    ): ?Task {
        if (!Uuid::isValid($uuid)) {
            return null;
        }
        $task = $em->getRepository(Task::class)->find(Uuid::fromString($uuid));
        if ($task === null || !$task->getUser()->getId()->equals($user->getId())) {
            return null;
        }

        return $task;
    }
}
