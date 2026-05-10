<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Clock\Clock;
use App\Message\ExpireSubscriptionsMessage;
use App\Service\Subscription\SubscriptionExpirer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ExpireSubscriptionsHandler
{
    public function __construct(
        private readonly SubscriptionExpirer $expirer,
        private readonly LoggerInterface $logger,
        private Clock $clock,
    ) {
    }

    public function __invoke(ExpireSubscriptionsMessage $message): void
    {
        $now = $this->clock->now();
        try {
            $this->expirer->tick($now);
        } catch (\Throwable $e) {
            $this->logger->critical('Expire subscriptions handler failed', [
                'now' => $now->format('c'),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
