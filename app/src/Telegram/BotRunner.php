<?php

declare(strict_types=1);

namespace App\Telegram;

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

        $doctrine = $this->doctrine;
        $bot->middleware(function (Nutgram $bot, $next) use ($doctrine): void {
            // Перед обработкой — чистим identity map от предыдущего update'а.
            // Без этого findBy может вернуть устаревшую сущность, которую
            // другой handler успел изменить и flush'нуть, но идентичность
            // не сброшена (например, task.status всё ещё PENDING в кеше).
            $this->cleanEm($doctrine);

            try {
                $next($bot);
            } finally {
                // После обработки — ещё раз, чтобы не тащить detached-сущности
                // в следующий update. Получаем EM из registry свежим на случай
                // если resetManager() был вызван во время обработки.
                $this->cleanEm($doctrine);
            }
        });

        return $bot;
    }

    private function cleanEm(ManagerRegistry $doctrine): void
    {
        $em = $doctrine->getManager();
        if (!$em->isOpen()) {
            $doctrine->resetManager();

            return;
        }
        $em->clear();
    }
}
