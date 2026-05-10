<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Domain\Subscription\Plan;
use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\PlanCatalog;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Простая аналитика подписок для /admin stats. Без графиков и хранилища
 * метрик — каждый счётчик считается ad-hoc через COUNT-запросы.
 *
 * MRR — упрощённая модель: количество active Pro × цена тарифа из конфига.
 * Cancelled-pro в MRR не включаем — после currentPeriodEnd денег уже нет.
 */
class SubscriptionStatsService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly PlanCatalog $catalog,
    ) {
    }

    /**
     * @return array{
     *   users: array{total: int, allowed: int, admins: int},
     *   subscriptions: array{trialing: int, active: int, cancelled: int, expired: int},
     *   mrr_rub_minor: int,
     *   last_7d: array{new_users: int, started_trials: int, converted: int, cancellations: int},
     * }
     */
    public function collect(\DateTimeImmutable $now): array
    {
        $em = $this->doctrine->getManager();
        $weekAgo = $now->modify('-7 days');

        $usersTotal = (int) $em->getRepository(User::class)
            ->createQueryBuilder('u')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $usersAllowed = (int) $em->getRepository(User::class)
            ->createQueryBuilder('u')->select('COUNT(u.id)')
            ->andWhere('u.isAllowed = true')
            ->getQuery()->getSingleScalarResult();
        $usersAdmins = (int) $em->getRepository(User::class)
            ->createQueryBuilder('u')->select('COUNT(u.id)')
            ->andWhere('u.isAdmin = true')
            ->getQuery()->getSingleScalarResult();

        $subRepo = $em->getRepository(Subscription::class);
        $trialing = (int) $subRepo->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.status = :s')->setParameter('s', SubscriptionStatus::Trialing)
            ->getQuery()->getSingleScalarResult();
        $active = (int) $subRepo->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.status = :s')->setParameter('s', SubscriptionStatus::Active)
            ->getQuery()->getSingleScalarResult();
        $cancelled = (int) $subRepo->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.status = :s')->setParameter('s', SubscriptionStatus::Cancelled)
            ->andWhere('s.currentPeriodEnd > :now')->setParameter('now', $now)
            ->getQuery()->getSingleScalarResult();
        $expired = (int) $subRepo->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.status = :s')->setParameter('s', SubscriptionStatus::Expired)
            ->getQuery()->getSingleScalarResult();

        $mrr = $active * $this->catalog->priceRubMinor(Plan::Pro);

        $newUsers = (int) $em->getRepository(User::class)
            ->createQueryBuilder('u')->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :w')->setParameter('w', $weekAgo)
            ->getQuery()->getSingleScalarResult();

        $startedTrials = (int) $subRepo->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.trialEndsAt IS NOT NULL')
            ->andWhere('s.createdAt >= :w')->setParameter('w', $weekAgo)
            ->getQuery()->getSingleScalarResult();

        $converted = (int) $subRepo->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.convertedFromTrialAt IS NOT NULL')
            ->andWhere('s.convertedFromTrialAt >= :w')->setParameter('w', $weekAgo)
            ->getQuery()->getSingleScalarResult();

        $cancellations = (int) $subRepo->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.cancelledAt IS NOT NULL')
            ->andWhere('s.cancelledAt >= :w')->setParameter('w', $weekAgo)
            ->getQuery()->getSingleScalarResult();

        return [
            'users' => [
                'total' => $usersTotal,
                'allowed' => $usersAllowed,
                'admins' => $usersAdmins,
            ],
            'subscriptions' => [
                'trialing' => $trialing,
                'active' => $active,
                'cancelled' => $cancelled,
                'expired' => $expired,
            ],
            'mrr_rub_minor' => $mrr,
            'last_7d' => [
                'new_users' => $newUsers,
                'started_trials' => $startedTrials,
                'converted' => $converted,
                'cancellations' => $cancellations,
            ],
        ];
    }
}
