<?php

declare(strict_types=1);

namespace App\Command\Smoke;

use App\Smoke\SmokeHarness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:smoke:reset',
    description: 'Удалить тестового пользователя smoke-тестов (telegram_id=999999999) и все его задачи.',
)]
final class SmokeResetCommand extends Command
{
    public function __construct(
        private readonly SmokeHarness $harness,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->harness->reset();

        $io->success(sprintf(
            'Reset ok: deleted %d task(s), %d user(s).',
            $result['tasks'],
            $result['user'],
        ));

        return Command::SUCCESS;
    }
}
