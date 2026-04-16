<?php

declare(strict_types=1);

namespace App\Telegram;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

class BotRunner
{
    public function __construct(
        private readonly HandlerRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(string $token): void
    {
        $bot = new Nutgram($token);

        $this->registry->register($bot);

        // Очищаем EM после каждого апдейта, чтобы не копить detached-сущности
        // и не держать stale-соединение в долгоживущем процессе.
        $em = $this->em;
        $doctrine = $this->doctrine;
        $bot->middleware(function (Nutgram $bot, $next) use ($em, $doctrine): void {
            try {
                $next($bot);
            } finally {
                if (!$em->isOpen()) {
                    $doctrine->resetManager();
                }
                $em->clear();
            }
        });

        $this->logger->info('Bot started, polling...');

        $bot->run();
    }
}
