<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Clock\Clock;
use App\Message\TriggerRebillMessage;
use App\Service\Subscription\Recurring\RebillScheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TriggerRebillHandler
{
    public function __construct(
        private readonly RebillScheduler $rebill,
        private readonly LoggerInterface $logger,
        private Clock $clock,
    ) {
    }

    public function __invoke(TriggerRebillMessage $message): void
    {
        $now = $this->clock->now();
        try {
            $this->rebill->run($now);
        } catch (\Throwable $e) {
            $this->logger->critical('Rebill scheduler failed', [
                'now' => $now->format('c'),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
