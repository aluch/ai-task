<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:list',
    description: 'List all users',
)]
class UserListCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = $this->users->findBy([], ['createdAt' => 'ASC']);

        if ($users === []) {
            $io->writeln('<comment>No users yet.</comment>');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                $user->getId()->toRfc4122(),
                $user->getTelegramId() ?? '-',
                $user->getName() ?? '-',
                $user->getTimezone(),
                $user->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(['ID', 'Telegram', 'Name', 'TZ', 'Created'], $rows);

        return Command::SUCCESS;
    }
}
