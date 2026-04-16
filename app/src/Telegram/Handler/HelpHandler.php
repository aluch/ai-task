<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use SergiX44\Nutgram\Nutgram;

class HelpHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: <<<'MSG'
            📋 Доступные команды:

            /start — приветствие и регистрация
            /help — эта справка
            /list — открытые задачи
            /done <id> — пометить задачу выполненной
            /snooze <id> <когда> — отложить задачу
            /block <task> <blocker> — задача task заблокирована blocker'ом
            /unblock <task> <blocker> — убрать зависимость
            /deps <id> — показать зависимости задачи

            Любой текст — создать задачу из текста.

            ID задачи — первые 8 символов из полного UUID (видны в /list).
            <когда> — +2h, +1d, tomorrow 09:00, 2026-04-20 18:00
            MSG,
        );
    }
}
