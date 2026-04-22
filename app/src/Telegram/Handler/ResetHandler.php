<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\AI\ConversationHistoryStore;
use App\Service\TelegramUserResolver;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * /reset — очищает историю диалога Ассистента для текущего пользователя.
 * Полезно когда контекст «поехал» или когда пользователь хочет начать
 * разговор с нуля, не дожидаясь истечения 30-минутного TTL.
 */
class ResetHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly ConversationHistoryStore $history,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $sizeBefore = $this->history->getSize($user);
        $this->history->clear($user);

        $this->logger->info('Assistant history reset', [
            'user_id' => $user->getId()->toRfc4122(),
            'size_before' => $sizeBefore,
        ]);

        $bot->sendMessage(text: '🆕 Диалог сброшен. Начинаем заново.');
    }
}
