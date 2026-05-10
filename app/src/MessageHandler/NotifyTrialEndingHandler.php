<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Clock\Clock;
use App\Message\NotifyTrialEndingMessage;
use App\Service\Subscription\TrialNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Тонкая обёртка вокруг TrialNotifier. Логика сложная — в сервисе,
 * чтобы прямой smoke-вызов работал без поднятия Messenger.
 */
#[AsMessageHandler]
final class NotifyTrialEndingHandler
{
    public function __construct(
        private readonly TrialNotifier $notifier,
        private readonly LoggerInterface $logger,
        private Clock $clock,
    ) {
    }

    public function __invoke(NotifyTrialEndingMessage $message): void
    {
        $now = $this->clock->now();
        try {
            $sent = $this->notifier->tick($now);
            if ($sent > 0) {
                $this->logger->info('Trial notifications dispatched', ['count' => $sent]);
            }
        } catch (\Throwable $e) {
            $this->logger->critical('Trial notify handler failed', [
                'now' => $now->format('c'),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
