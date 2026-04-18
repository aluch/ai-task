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
            /list — открытые задачи (PENDING + IN_PROGRESS). Фильтры:
              /list все | /list done | /list snoozed
            /done — пометить задачу выполненной (интерактивно или /done <id>)
            /snooze — отложить задачу (интерактивно или /snooze <id> <когда>)
            /block — добавить зависимость (интерактивно или /block <task> <blocker>)
            /unblock — убрать зависимость (интерактивно или /unblock <task> <blocker>)
            /deps — показать зависимости (интерактивно или /deps <id>)
            /free <время> [контекст] — AI подберёт задачи.
              Примеры: /free 2h, /free 30m дома, /free 1h на улице

            Любой текст — создать задачу из текста.

            ID задачи — первые 8 символов UUID (видны в /list).
            <когда> — +2h, +1d, tomorrow 09:00, 2026-04-20 18:00
            MSG,
        );
    }
}
