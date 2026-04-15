<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:task:list',
    description: 'List tasks (optionally filtered by user/status)',
)]
class TaskListCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TaskRepository $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User UUID or telegram id')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userId = $input->getOption('user-id');
        if ($userId === null) {
            $io->error('--user-id is required.');

            return Command::FAILURE;
        }

        $user = $this->users->findByIdentifier((string) $userId);
        if ($user === null) {
            $io->error(sprintf('User not found by identifier "%s".', $userId));

            return Command::FAILURE;
        }

        $status = null;
        if (($statusRaw = $input->getOption('status')) !== null) {
            $status = TaskStatus::tryFrom((string) $statusRaw);
            if ($status === null) {
                $io->error('Invalid --status.');

                return Command::FAILURE;
            }
        }

        $limit = (int) $input->getOption('limit');
        $tasks = $this->tasks->findForUser($user, $status, $limit);

        if ($tasks === []) {
            $io->writeln('<comment>No tasks.</comment>');

            return Command::SUCCESS;
        }

        $userTz = new \DateTimeZone($user->getTimezone());
        $fmt = static fn (?\DateTimeImmutable $dt): string => $dt === null
            ? '-'
            : $dt->setTimezone($userTz)->format('Y-m-d H:i');

        $rows = [];
        foreach ($tasks as $task) {
            $contextCodes = array_map(fn ($c) => $c->getCode(), $task->getContexts()->toArray());
            $rows[] = [
                substr($task->getId()->toRfc4122(), 0, 8) . '…',
                $task->getTitle(),
                $task->getStatus()->value,
                $task->getPriority()->value,
                $fmt($task->getDeadline()),
                $fmt($task->getSnoozedUntil()),
                implode(',', $contextCodes) ?: '-',
                $fmt($task->getCompletedAt()),
            ];
        }

        $io->writeln(sprintf('<info>Times shown in user timezone: %s (local)</info>', $user->getTimezone()));
        $io->table(
            ['ID', 'Title', 'Status', 'Pri', 'Deadline', 'Snoozed→', 'Contexts', 'Done at'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
