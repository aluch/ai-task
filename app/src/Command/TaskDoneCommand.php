<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:task:done',
    description: 'Mark a task as DONE',
)]
class TaskDoneCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Task UUID (full or first segment is not enough)');
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

        $task->markDone();
        $this->em->flush();

        $io->success(sprintf(
            'Task %s marked DONE at %s.',
            $task->getId()->toRfc4122(),
            $task->getCompletedAt()?->format('Y-m-d H:i:s'),
        ));

        return Command::SUCCESS;
    }
}
