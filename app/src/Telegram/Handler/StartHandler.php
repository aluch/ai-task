<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Service\TelegramUserResolver;
use SergiX44\Nutgram\Nutgram;

class StartHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $name = $user->getName() ?? 'друг';

        $bot->sendMessage(
            text: <<<MSG
            Привет, {$name}! 👋

            Я Помни — твой помощник по делам.

            Просто пиши свободным текстом, как другу:
            • «Купить хлеб через час»
            • «Завтра в 15:00 встреча, предупреди заранее»
            • «Что у меня на сегодня?»
            • «Свободен 2 часа, что взять?»

            Я разберусь сам — создам задачу, напомню вовремя, подберу что сделать.

            Все команды — /help.
            MSG,
        );
    }
}
