<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TaskRepository;
use App\Service\RelativeTimeParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:task:snooze',
    description: 'Snooze a task until a given moment (relative or absolute)',
)]
class TaskSnoozeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository $tasks,
        private readonly RelativeTimeParser $timeParser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Task UUID')
            ->addArgument('until', InputArgument::REQUIRED, 'When to wake up: +2h, +1d, "tomorrow 9am", "2026-04-20 18:00"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $idRaw = (string) $input->getArgument('id');
        if (!Uuid::isValid($idRaw)) {
            $io->error('Argument <id> must be a valid UUID.');

            return Command::FAILURE;
        }

        $task = $this->tasks->find(Uuid::fromString($idRaw));
        if ($task === null) {
            $io->error(sprintf('Task %s not found.', $idRaw));

            return Command::FAILURE;
        }

        $userTz = new \DateTimeZone($task->getUser()->getTimezone());
        $rawUntil = (string) $input->getArgument('until');

        $until = $this->timeParser->parse($rawUntil, $userTz);
        if ($until === null) {
            $io->error(sprintf('Cannot parse <until>: %s', $rawUntil));

            return Command::FAILURE;
        }

        if ($until <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $io->error('<until> must be in the future.');

            return Command::FAILURE;
        }

        $task->snooze($until);
        $this->em->flush();

        $localUntil = $until->setTimezone($userTz);
        $io->success(sprintf(
            'Task %s snoozed until %s (%s local / %s UTC).',
            $task->getId()->toRfc4122(),
            $localUntil->format('Y-m-d H:i'),
            $task->getUser()->getTimezone(),
            $until->format('Y-m-d H:i'),
        ));

        return Command::SUCCESS;
    }
}
