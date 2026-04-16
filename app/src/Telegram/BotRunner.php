<?php

declare(strict_types=1);

namespace App\Telegram;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Polling;

class BotRunner
{
    public function __construct(
        private readonly HandlerRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Запускает long-polling с автоматическим reconnect при транзиентных
     * сетевых ошибках. Блокирует до SIGTERM/SIGINT.
     *
     * Nutgram сам обрабатывает SIGTERM (ставит Polling::$FOREVER = false и
     * возвращает управление из run()) и SIGINT (exit). Поэтому собственные
     * pcntl_signal мы не ставим.
     *
     * Если run() вернулся без исключения — это graceful shutdown → выходим.
     * При ConnectException/ServerException — ждём и пересоздаём бот-инстанс.
     */
    public function run(string $token): void
    {
        $this->logger->info('Bot started, polling...');

        while (true) {
            try {
                Polling::$FOREVER = true;
                $bot = $this->createBot($token);
                $bot->run();

                // run() вернулся без исключения → SIGTERM → graceful exit
                break;
            } catch (ConnectException $e) {
                if (!Polling::$FOREVER) {
                    break; // SIGTERM interrupted the cURL call — shutdown
                }
                $this->logger->warning('Transient network error, reconnecting in 1s', [
                    'error' => $e->getMessage(),
                ]);
                sleep(1);
            } catch (ServerException $e) {
                if (!Polling::$FOREVER) {
                    break;
                }
                $this->logger->warning('Telegram 5xx error, reconnecting in 3s', [
                    'error' => $e->getMessage(),
                    'status' => $e->getResponse()->getStatusCode(),
                ]);
                sleep(3);
            }
        }

        $this->logger->info('Bot stopped gracefully.');
    }

    private function createBot(string $token): Nutgram
    {
        $bot = new Nutgram($token, new Configuration(
            clientTimeout: 35,
            pollingTimeout: 30,
        ));

        $this->registry->register($bot);

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

        return $bot;
    }
}
