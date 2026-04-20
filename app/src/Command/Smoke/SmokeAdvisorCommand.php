<?php

declare(strict_types=1);

namespace App\Command\Smoke;

use App\AI\TaskAdvisor;
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
    name: 'app:smoke:advisor',
    description: 'Прогнать TaskAdvisor::suggest для test-user\'а (задачи должны быть созданы через app:smoke:assistant --keep).',
)]
final class SmokeAdvisorCommand extends Command
{
    public function __construct(
        private readonly SmokeHarness $harness,
        private readonly TaskAdvisor $advisor,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('minutes', InputArgument::REQUIRED, 'Доступное время в минутах')
            ->addArgument('context', InputArgument::OPTIONAL, 'Контекст пользователя (например «дома с ноутбуком»).')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Зафиксировать текущее время.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->harness->ensureTestUser();

        $minutes = (int) $input->getArgument('minutes');
        $context = $input->getArgument('context');

        $nowOpt = $input->getOption('now');
        $now = $nowOpt
            ? new \DateTimeImmutable((string) $nowOpt)
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $tasks = $this->doctrine->getManager()
            ->getRepository(Task::class)
            ->findForUser($user);

        $io->section('Input');
        $io->writeln("User tasks: " . count($tasks));
        $io->writeln("Available:  {$minutes} min");
        $io->writeln('Context:    ' . ($context ?? '—'));

        if ($tasks === []) {
            $io->warning('У test-user\'а нет задач. Сначала запусти: app:smoke:assistant "Купить молоко" и ещё пару раз с --keep.');

            return Command::SUCCESS;
        }

        $dto = $this->advisor->suggest($user, $minutes, $context, $tasks, $now);

        $io->section('Output');
        if ($dto->noMatchReason !== null) {
            $io->writeln('No match: ' . $dto->noMatchReason);
        }
        if ($dto->userSummary !== null) {
            $io->writeln('Summary: ' . $dto->userSummary);
        }
        if ($dto->internalReasoning !== null) {
            $io->writeln('Reasoning: ' . $dto->internalReasoning);
        }

        $io->writeln('Suggestions (' . count($dto->suggestions) . '):');
        $em = $this->doctrine->getManager();
        foreach ($dto->suggestions as $s) {
            $title = '?';
            $full = $em->getRepository(Task::class)->find($s->taskId);
            if ($full !== null) {
                $title = $full->getTitle();
            }
            $io->writeln(sprintf(
                '  #%d [%s] %s — %s мин%s',
                $s->order,
                substr($s->taskId, 0, 8),
                $title,
                $s->estimatedMinutes ?? '?',
                $s->tip !== null ? ' | ' . $s->tip : '',
            ));
        }
        $io->writeln("Total: {$dto->totalEstimatedMinutes} мин");

        return Command::SUCCESS;
    }
}
