<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\CyclicDependencyException;
use App\Repository\TaskRepository;
use App\Service\DependencyValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:task:block',
    description: 'Add a blocker dependency between two tasks',
)]
class TaskBlockCommand extends Command
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly DependencyValidator $depValidator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('blocked-id', InputArgument::REQUIRED, 'UUID of the task to block')
            ->addArgument('blocker-id', InputArgument::REQUIRED, 'UUID of the blocking task');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $blockedId = (string) $input->getArgument('blocked-id');
        $blockerId = (string) $input->getArgument('blocker-id');

        if (!Uuid::isValid($blockedId) || !Uuid::isValid($blockerId)) {
            $io->error('Both arguments must be valid UUIDs.');

            return Command::FAILURE;
        }

        $blocked = $this->tasks->find(Uuid::fromString($blockedId));
        $blocker = $this->tasks->find(Uuid::fromString($blockerId));

        if ($blocked === null || $blocker === null) {
            $io->error('One or both tasks not found.');

            return Command::FAILURE;
        }

        if ($blocked->getBlockedBy()->contains($blocker)) {
            $io->note('Dependency already exists.');

            return Command::SUCCESS;
        }

        try {
            $this->depValidator->validateNoCycle($blocked, $blocker);
        } catch (\LogicException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $blocked->addBlocker($blocker);
        $this->em->flush();

        $io->success(sprintf(
            '"%s" is now blocked by "%s".',
            $blocked->getTitle(),
            $blocker->getTitle(),
        ));

        return Command::SUCCESS;
    }
}
