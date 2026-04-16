<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Telegram\Handler\BlockHandler;
use App\Telegram\Handler\DepsHandler;
use App\Telegram\Handler\DoneHandler;
use App\Telegram\Handler\FreeTextHandler;
use App\Telegram\Handler\HelpHandler;
use App\Telegram\Handler\ListHandler;
use App\Telegram\Handler\SnoozeHandler;
use App\Telegram\Handler\StartHandler;
use App\Telegram\Handler\UnblockHandler;
use App\Telegram\Middleware\WhitelistMiddleware;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

class HandlerRegistry
{
    public function __construct(
        private readonly WhitelistMiddleware $whitelistMiddleware,
        private readonly StartHandler $startHandler,
        private readonly HelpHandler $helpHandler,
        private readonly ListHandler $listHandler,
        private readonly DoneHandler $doneHandler,
        private readonly SnoozeHandler $snoozeHandler,
        private readonly BlockHandler $blockHandler,
        private readonly UnblockHandler $unblockHandler,
        private readonly DepsHandler $depsHandler,
        private readonly FreeTextHandler $freeTextHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(Nutgram $bot): void
    {
        $bot->middleware($this->whitelistMiddleware);

        $bot->onCommand('start', $this->startHandler);
        $bot->onCommand('help', $this->helpHandler);
        $bot->onCommand('list', $this->listHandler);
        $bot->onCommand('done', $this->doneHandler);
        $bot->onCommand('snooze', $this->snoozeHandler);
        $bot->onCommand('block', $this->blockHandler);
        $bot->onCommand('unblock', $this->unblockHandler);
        $bot->onCommand('deps', $this->depsHandler);

        $freeTextHandler = $this->freeTextHandler;
        $bot->fallback(function (Nutgram $bot) use ($freeTextHandler): void {
            $text = $bot->message()?->text;
            if ($text === null) {
                return;
            }

            if (str_starts_with($text, '/')) {
                $bot->sendMessage(text: 'Неизвестная команда. Напиши /help для списка команд.');

                return;
            }

            ($freeTextHandler)($bot);
        });

        $logger = $this->logger;
        $bot->onApiError(function (Nutgram $bot, $e) use ($logger): void {
            $logger->error('Telegram API error', ['exception' => $e]);
            $bot->sendMessage(text: 'Что-то пошло не так, попробуй ещё раз.');
        });

        $bot->onException(function (Nutgram $bot, \Throwable $e) use ($logger): void {
            $logger->error('Unhandled bot exception', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            try {
                $bot->sendMessage(text: 'Что-то пошло не так, попробуй ещё раз. Если повторится — посмотри логи.');
            } catch (\Throwable) {
            }
        });
    }
}
