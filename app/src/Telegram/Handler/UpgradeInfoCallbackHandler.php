<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use SergiX44\Nutgram\Nutgram;

/**
 * Заглушка для callback'а «💎 Узнать про Pro» из soft-block-сообщения.
 * В S2 показывает alert «Команда /upgrade появится скоро» — настоящий
 * экран /upgrade появится в S3.
 */
class UpgradeInfoCallbackHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->answerCallbackQuery(
            text: 'Скоро появится /upgrade',
            show_alert: true,
        );
    }
}
