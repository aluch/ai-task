<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a new User',
)]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('telegram-id', null, InputOption::VALUE_REQUIRED, 'Telegram chat id (bigint)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Timezone (default Europe/Tallinn)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $telegramId = $input->getOption('telegram-id');
        $name = $input->getOption('name');
        $timezone = $input->getOption('timezone');

        if ($telegramId !== null && $this->users->findByTelegramId($telegramId) !== null) {
            $io->error(sprintf('User with telegram id %s already exists.', $telegramId));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setTelegramId($telegramId !== null ? (string) $telegramId : null);
        $user->setName($name);

        if ($timezone !== null) {
            $user->setTimezone($timezone);
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf(
            'User created: id=%s, telegram=%s, name=%s',
            $user->getId()->toRfc4122(),
            $user->getTelegramId() ?? '-',
            $user->getName() ?? '-',
        ));

        return Command::SUCCESS;
    }
}
