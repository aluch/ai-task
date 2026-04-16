<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TaskRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:task:deps',
    description: 'Show dependencies of a task',
)]
class TaskDepsCommand extends Command
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Task UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $idRaw = (string) $input->getArgument('id');
        if (!Uuid::isValid($idRaw)) {
            $io->error('Argument must be a valid UUID.');

            return Command::FAILURE;
        }

        $task = $this->tasks->find(Uuid::fromString($idRaw));
        if ($task === null) {
            $io->error('Task not found.');

            return Command::FAILURE;
        }

        $io->title($task->getTitle());

        $blockers = $task->getBlockedBy()->toArray();
        $io->section('Blocked by');
        if ($blockers === []) {
            $io->writeln('  (none)');
        } else {
            foreach ($blockers as $b) {
                $io->writeln(sprintf('  • %s (%s) — %s', $b->getTitle(), $b->getStatus()->value, $b->getId()->toRfc4122()));
            }
        }

        $blocking = $task->getBlockedTasks()->toArray();
        $io->section('Blocks');
        if ($blocking === []) {
            $io->writeln('  (none)');
        } else {
            foreach ($blocking as $b) {
                $io->writeln(sprintf('  • %s (%s) — %s', $b->getTitle(), $b->getStatus()->value, $b->getId()->toRfc4122()));
            }
        }

        $io->writeln('');
        $io->writeln(sprintf('Status: %s | isBlocked: %s', $task->getStatus()->value, $task->isBlocked() ? 'yes' : 'no'));

        return Command::SUCCESS;
    }
}
