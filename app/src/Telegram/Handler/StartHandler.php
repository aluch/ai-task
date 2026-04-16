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

            Я твой помощник по задачам. Пока умею немного — пиши /help чтобы посмотреть команды.

            Или просто отправь мне текст — я создам задачу.
            MSG,
        );
    }
}
