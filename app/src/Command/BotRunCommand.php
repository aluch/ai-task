<?php

declare(strict_types=1);

namespace App\Command;

use App\Telegram\BotRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bot:run',
    description: 'Start the Telegram bot (long polling, blocking)',
)]
class BotRunCommand extends Command
{
    public function __construct(
        private readonly BotRunner $botRunner,
        private readonly string $telegramToken,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->telegramToken === '') {
            $io->warning([
                'TELEGRAM_BOT_TOKEN is not set.',
                'Get a token from @BotFather, add it to .env, and restart.',
            ]);

            // Exit 0 — not a crash, just not configured yet.
            // docker-compose restart: on-failure will NOT restart on exit 0.
            return Command::SUCCESS;
        }

        $io->info('Starting bot in long-polling mode. Press Ctrl+C to stop.');

        $this->botRunner->run($this->telegramToken);

        return Command::SUCCESS;
    }
}
