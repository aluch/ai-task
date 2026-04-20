<?php

declare(strict_types=1);

namespace App\Command\Smoke;

use App\AI\Assistant;
use App\Entity\Task;
use App\Smoke\SmokeHarness;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:smoke:assistant',
    description: 'Прогнать Assistant с заданным сообщением для smoke test-user\'а. Показывает reply, tools, tokens, изменения БД.',
)]
final class SmokeAssistantCommand extends Command
{
    public function __construct(
        private readonly SmokeHarness $harness,
        private readonly Assistant $assistant,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::REQUIRED, 'Сообщение от имени test-user\'а')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Не делать reset перед вызовом (для цепочки тестов).')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Зафиксировать текущее время (ISO или parsable). Пример: "2026-04-20 15:00 Europe/Tallinn".');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $message = (string) $input->getArgument('message');

        if (!$input->getOption('keep')) {
            $this->harness->reset();
        }
        $user = $this->harness->ensureTestUser();

        $nowOpt = $input->getOption('now');
        if ($nowOpt) {
            $now = new \DateTimeImmutable((string) $nowOpt);
            $this->harness->freezeTimeAt($now);
        } else {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        $io->section('Input');
        $io->writeln("User: {$user->getName()} (tg:{$user->getTelegramId()}, tz:{$user->getTimezone()})");
        $io->writeln('Now:  ' . $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC');
        $io->writeln("Msg:  {$message}");

        $tasksBefore = $this->taskIds($user);

        $result = $this->assistant->handle($user, $message, $now);

        $io->section('Output');
        $io->writeln('Reply:      ' . $result->replyText);
        $io->writeln('Tools:      ' . (implode(', ', $result->toolsCalled) ?: '(none)'));
        $io->writeln("Iterations: {$result->iterations}");
        $io->writeln("Tokens:     {$result->inputTokens} in / {$result->outputTokens} out");

        $tasksAfter = $this->taskIds($user);
        $newIds = array_diff($tasksAfter, $tasksBefore);

        if ($newIds !== []) {
            $io->section('Created tasks (' . count($newIds) . ')');
            foreach ($newIds as $id) {
                $t = $this->doctrine->getManager()->getRepository(Task::class)->find($id);
                if ($t === null) {
                    continue;
                }
                $io->writeln($this->formatTask($t));
            }
        }

        $io->section('Telegram messages captured (' . $this->harness->notifier()->count() . ')');
        foreach ($this->harness->notifier()->getMessages() as $i => $m) {
            $io->writeln(sprintf('#%d → %s:', $i + 1, $m['chat_id']));
            $io->writeln('  ' . str_replace("\n", "\n  ", $m['text']));
        }

        return Command::SUCCESS;
    }

    /** @return string[] */
    private function taskIds(\App\Entity\User $user): array
    {
        $em = $this->doctrine->getManager();
        $tasks = $em->getRepository(Task::class)->findBy(['user' => $user]);

        return array_map(fn (Task $t) => $t->getId()->toRfc4122(), $tasks);
    }

    private function formatTask(Task $t): string
    {
        $tz = new \DateTimeZone($t->getUser()->getTimezone());
        $deadline = $t->getDeadline()?->setTimezone($tz)->format('Y-m-d H:i T') ?? 'null';

        return sprintf(
            '  [%s] %s | deadline=%s | priority=%s | remind_before=%s | interval=%s',
            substr($t->getId()->toRfc4122(), 0, 8),
            $t->getTitle(),
            $deadline,
            $t->getPriority()->value,
            $t->getRemindBeforeDeadlineMinutes() ?? 'null',
            $t->getReminderIntervalMinutes() ?? 'null',
        );
    }
}
