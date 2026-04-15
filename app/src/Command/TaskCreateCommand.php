<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Task;
use App\Enum\TaskPriority;
use App\Repository\TaskContextRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:task:create',
    description: 'Create a new Task for a user',
)]
class TaskCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly TaskContextRepository $contexts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User UUID or telegram id')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Task title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Task description')
            ->addOption('deadline', null, InputOption::VALUE_REQUIRED, 'Deadline (Y-m-d H:i or Y-m-d)')
            ->addOption('estimated-minutes', null, InputOption::VALUE_REQUIRED, 'Estimated effort in minutes')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'low|medium|high|urgent', 'medium')
            ->addOption('contexts', null, InputOption::VALUE_REQUIRED, 'Comma-separated context codes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userId = $input->getOption('user-id');
        $title = $input->getOption('title');

        if ($userId === null || $title === null || $title === '') {
            $io->error('Both --user-id and --title are required.');

            return Command::FAILURE;
        }

        $user = $this->users->findByIdentifier((string) $userId);
        if ($user === null) {
            $io->error(sprintf('User not found by identifier "%s".', $userId));

            return Command::FAILURE;
        }

        $task = new Task($user, (string) $title);

        if (($description = $input->getOption('description')) !== null) {
            $task->setDescription((string) $description);
        }

        if (($deadlineRaw = $input->getOption('deadline')) !== null) {
            $deadline = $this->parseDeadline((string) $deadlineRaw);
            if ($deadline === null) {
                $io->error('Invalid --deadline. Use Y-m-d H:i or Y-m-d.');

                return Command::FAILURE;
            }
            $task->setDeadline($deadline);
        }

        if (($estimated = $input->getOption('estimated-minutes')) !== null) {
            $task->setEstimatedMinutes((int) $estimated);
        }

        $priority = TaskPriority::tryFrom((string) $input->getOption('priority'));
        if ($priority === null) {
            $io->error('Invalid --priority. Use low|medium|high|urgent.');

            return Command::FAILURE;
        }
        $task->setPriority($priority);

        if (($codesRaw = $input->getOption('contexts')) !== null && $codesRaw !== '') {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string) $codesRaw))));
            $found = $this->contexts->findByCodes($codes);
            $foundCodes = array_map(fn ($c) => $c->getCode(), $found);
            $missing = array_diff($codes, $foundCodes);
            if ($missing !== []) {
                $io->error(sprintf('Unknown context codes: %s', implode(', ', $missing)));

                return Command::FAILURE;
            }
            foreach ($found as $context) {
                $task->addContext($context);
            }
        }

        $this->em->persist($task);
        $this->em->flush();

        $io->success(sprintf(
            'Task created: %s — "%s" (priority=%s)',
            $task->getId()->toRfc4122(),
            $task->getTitle(),
            $task->getPriority()->value,
        ));

        return Command::SUCCESS;
    }

    private function parseDeadline(string $raw): ?\DateTimeImmutable
    {
        foreach (['Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        return null;
    }
}
