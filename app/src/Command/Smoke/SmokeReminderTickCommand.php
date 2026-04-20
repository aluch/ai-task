<?php

declare(strict_types=1);

namespace App\Command\Smoke;

use App\Entity\Task;
use App\Message\CheckDeadlineRemindersMessage;
use App\Message\CheckPeriodicRemindersMessage;
use App\Message\CheckSnoozeWakeupsMessage;
use App\MessageHandler\CheckDeadlineRemindersHandler;
use App\MessageHandler\CheckPeriodicRemindersHandler;
use App\MessageHandler\CheckSnoozeWakeupsHandler;
use App\Notification\ReminderSender;
use App\Notification\SendResult;
use App\Smoke\SmokeHarness;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:smoke:reminder-tick',
    description: 'Вручную прогнать один tick scheduler handler\'а. --type=deadline|periodic|snooze|all.',
)]
final class SmokeReminderTickCommand extends Command
{
    public function __construct(
        private readonly SmokeHarness $harness,
        private readonly ManagerRegistry $doctrine,
        private readonly CheckDeadlineRemindersHandler $deadlineHandler,
        private readonly CheckPeriodicRemindersHandler $periodicHandler,
        private readonly CheckSnoozeWakeupsHandler $snoozeHandler,
        private readonly ReminderSender $sender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'deadline | periodic | snooze | all', 'all')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Фиксированный «сейчас». Пример: "2026-04-20 15:00 UTC".')
            ->addOption('include-real-users', null, InputOption::VALUE_NONE, 'Не фильтровать кандидатов по test-user\'у. Полезно для отладки prod-данных.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = (string) $input->getOption('type');

        if (($n = $input->getOption('now')) !== null) {
            $this->harness->freezeTimeAt(new \DateTimeImmutable((string) $n));
        }

        $now = $this->harness->clock()?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $io->writeln('Now: ' . $now->format('Y-m-d H:i:s') . ' UTC');
        $io->newLine();

        $repo = $this->doctrine->getManager()->getRepository(Task::class);
        $includeReal = (bool) $input->getOption('include-real-users');
        $testUser = $this->harness->findTestUser();
        $filter = fn (array $tasks): array => $includeReal
            ? $tasks
            : array_values(array_filter(
                $tasks,
                fn (Task $t) => $testUser !== null && $t->getUser()->getId()->equals($testUser->getId()),
            ));

        if ($type === 'deadline' || $type === 'all') {
            $this->tick($io, 'deadline', $filter($repo->findDeadlineReminderCandidates($now)), function (Task $t) {
                return $this->sender->sendDeadlineReminder($t);
            });
        }

        if ($type === 'periodic' || $type === 'all') {
            $this->tick($io, 'periodic', $filter($repo->findPeriodicReminderCandidates($now)), function (Task $t) {
                return $this->sender->sendPeriodicReminder($t);
            });
        }

        if ($type === 'snooze' || $type === 'all') {
            $this->tick($io, 'snooze', $filter($repo->findSnoozeWakeupCandidates($now)), function (Task $t) {
                return $this->sender->sendSnoozeWakeup($t);
            });
        }

        $this->printCapturedMessages($io);

        return Command::SUCCESS;
    }

    /**
     * @param Task[] $candidates
     * @param callable(Task): SendResult $send
     */
    private function tick(SymfonyStyle $io, string $label, array $candidates, callable $send): void
    {
        $io->section("tick: {$label}");
        $io->writeln('Candidates: ' . count($candidates));
        foreach ($candidates as $t) {
            $io->writeln(sprintf(
                '  - [%s] %s (status=%s, deadline=%s, last_reminded=%s, snoozed_until=%s)',
                substr($t->getId()->toRfc4122(), 0, 8),
                $t->getTitle(),
                $t->getStatus()->value,
                $t->getDeadline()?->format('c') ?? 'null',
                $t->getLastRemindedAt()?->format('c') ?? 'null',
                $t->getSnoozedUntil()?->format('c') ?? 'null',
            ));
        }

        if ($candidates === []) {
            return;
        }

        $io->writeln('Results:');
        foreach ($candidates as $t) {
            $result = $send($t);
            $io->writeln(sprintf('  - [%s] %s', substr($t->getId()->toRfc4122(), 0, 8), $result->value));
        }
    }

    private function printCapturedMessages(SymfonyStyle $io): void
    {
        $messages = $this->harness->notifier()->getMessages();
        $io->section('Messages captured: ' . count($messages));
        foreach ($messages as $i => $m) {
            $io->writeln(sprintf('#%d → chat %s:', $i + 1, $m['chat_id']));
            $io->writeln('  ' . str_replace("\n", "\n  ", $m['text']));
        }
    }
}
