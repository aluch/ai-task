<?php

declare(strict_types=1);

namespace App\Telegram\Middleware;

use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

class WhitelistMiddleware
{
    /** @var int[] */
    private readonly array $allowedIds;

    public function __construct(
        string $allowedUserIds,
        private readonly LoggerInterface $logger,
    ) {
        $ids = array_filter(array_map('trim', explode(',', $allowedUserIds)));
        $this->allowedIds = array_map('intval', $ids);
    }

    public function __invoke(Nutgram $bot, $next): void
    {
        if ($this->allowedIds === []) {
            $next($bot);

            return;
        }

        $userId = $bot->userId();
        if ($userId === null || !in_array($userId, $this->allowedIds, true)) {
            $this->logger->warning('Telegram: rejected message from non-whitelisted user', [
                'telegram_id' => $userId,
                'username' => $bot->message()?->from?->username,
            ]);

            return;
        }

        $next($bot);
    }
}
