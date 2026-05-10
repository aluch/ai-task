<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\Subscription;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Перевод истёкших usable-подписок (trialing/active/cancelled) в expired.
 * Запускается scheduler'ом раз в N минут. Триал по концу 7 дней, Pro по
 * concу оплаченного периода (до S5 — без auto-rebill).
 *
 * Возвращает количество переведённых записей, чтобы handler/smoke могли
 * проверить что что-то реально произошло.
 */
class SubscriptionExpirer
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionService $subscriptions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function tick(\DateTimeImmutable $now): int
    {
        $em = $this->doctrine->getManager();
        $rows = $em->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->where('s.currentPeriodEnd < :now')
            ->andWhere('s.status IN (:usable)')
            ->setParameter('now', $now)
            ->setParameter('usable', [
                SubscriptionStatus::Trialing,
                SubscriptionStatus::Active,
                SubscriptionStatus::Cancelled,
            ])
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($rows as $sub) {
            $this->subscriptions->expire($sub, $now);
            $count++;
        }
        if ($count > 0) {
            $this->logger->info('Subscriptions expired', ['count' => $count]);
        }

        return $count;
    }
}
