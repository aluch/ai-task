<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Domain\Subscription\Plan;
use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\PlanCatalog;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Главный сервис управления подписками. Все мутации идут через него,
 * никто не лезет в EM напрямую (см. CLAUDE.md long-running rules).
 */
class SubscriptionService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly PlanCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Стартует триал. Идемпотентен: если у пользователя ЕЩЁ ХОТЬ КОГДА-ТО
     * была подписка (триал/Pro/expired) — null. Защита от абуза «удалить
     * аккаунт → создать заново → ещё триал».
     */
    public function startTrial(User $user, \DateTimeImmutable $now): ?Subscription
    {
        $em = $this->doctrine->getManager();
        $existing = $em->getRepository(Subscription::class)->findOneBy(['user' => $user]);
        if ($existing !== null) {
            return null;
        }

        $trialEnd = $now->modify('+' . $this->catalog->trialDays() . ' days');
        $sub = new Subscription(
            user: $user,
            plan: Plan::Pro,
            status: SubscriptionStatus::Trialing,
            currentPeriodStart: $now,
            currentPeriodEnd: $trialEnd,
        );
        $sub->setTrialEndsAt($trialEnd);

        $em->persist($sub);
        $em->flush();

        $this->logger->info('Trial started', [
            'user_id' => $user->getId()->toRfc4122(),
            'until' => $trialEnd->format('c'),
        ]);

        return $sub;
    }

    /**
     * Активирует Pro (после успешного платежа). Если был триал/cancelled —
     * переводим на Active с новым billing-периодом. Если до этого статус
     * был Trialing — фиксируем convertedFromTrialAt для аналитики
     * конверсии (см. SubscriptionStatsService).
     */
    public function activatePro(
        User $user,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        ?string $externalSubscriptionId,
        \DateTimeImmutable $now,
    ): Subscription {
        $em = $this->doctrine->getManager();
        $sub = $this->getActiveSubscription($user);

        if ($sub === null) {
            $sub = new Subscription(
                user: $user,
                plan: Plan::Pro,
                status: SubscriptionStatus::Active,
                currentPeriodStart: $periodStart,
                currentPeriodEnd: $periodEnd,
            );
            $em->persist($sub);
        } else {
            $wasTrial = $sub->getStatus() === SubscriptionStatus::Trialing;
            $sub->setPlan(Plan::Pro);
            $sub->setStatus(SubscriptionStatus::Active);
            $sub->setCurrentPeriodStart($periodStart);
            $sub->setCurrentPeriodEnd($periodEnd);
            $sub->setCancelledAt(null);
            $sub->setTrialEndsAt(null);
            if ($wasTrial && $sub->getConvertedFromTrialAt() === null) {
                $sub->setConvertedFromTrialAt($now);
            }
        }
        if ($externalSubscriptionId !== null) {
            $sub->setExternalSubscriptionId($externalSubscriptionId);
        }
        $em->flush();

        $this->logger->info('Pro activated', [
            'user_id' => $user->getId()->toRfc4122(),
            'until' => $periodEnd->format('c'),
        ]);

        return $sub;
    }

    /**
     * Админский force-trial. В отличие от {@see startTrial} — не идемпотентен:
     * существующая подписка любого статуса схлопывается в expired, после чего
     * создаётся свежий триал на trialDays. Используется только из /admin
     * grant_trial.
     */
    public function forceStartTrial(User $user, \DateTimeImmutable $now): Subscription
    {
        $em = $this->doctrine->getManager();
        $existing = $this->getActiveSubscription($user);
        if ($existing !== null) {
            $this->expire($existing, $now);
        }

        $trialEnd = $now->modify('+' . $this->catalog->trialDays() . ' days');
        $sub = new Subscription(
            user: $user,
            plan: Plan::Pro,
            status: SubscriptionStatus::Trialing,
            currentPeriodStart: $now,
            currentPeriodEnd: $trialEnd,
        );
        $sub->setTrialEndsAt($trialEnd);

        $em->persist($sub);
        $em->flush();

        $this->logger->info('Admin force-trial granted', [
            'user_id' => $user->getId()->toRfc4122(),
            'until' => $trialEnd->format('c'),
        ]);

        return $sub;
    }

    /**
     * Админский force-Pro: подарить N дней Pro без оплаты.
     * externalSubscriptionId остаётся null (это не реальная подписка
     * у платёжного провайдера). Существующая usable-подписка
     * предварительно гасится в expired.
     */
    public function forceActivatePro(
        User $user,
        int $days,
        \DateTimeImmutable $now,
    ): Subscription {
        if ($days < 1) {
            throw new \InvalidArgumentException('days must be >= 1');
        }
        $em = $this->doctrine->getManager();
        $existing = $this->getActiveSubscription($user);
        if ($existing !== null) {
            $this->expire($existing, $now);
        }

        $periodEnd = $now->modify('+' . $days . ' days');
        $sub = new Subscription(
            user: $user,
            plan: Plan::Pro,
            status: SubscriptionStatus::Active,
            currentPeriodStart: $now,
            currentPeriodEnd: $periodEnd,
        );

        $em->persist($sub);
        $em->flush();

        $this->logger->info('Admin force-Pro granted', [
            'user_id' => $user->getId()->toRfc4122(),
            'days' => $days,
            'until' => $periodEnd->format('c'),
        ]);

        return $sub;
    }

    public function cancel(Subscription $subscription, \DateTimeImmutable $now): void
    {
        $subscription->setStatus(SubscriptionStatus::Cancelled);
        $subscription->setCancelledAt($now);
        $this->doctrine->getManager()->flush();
        $this->logger->info('Subscription cancelled', ['id' => $subscription->getId()->toRfc4122()]);
    }

    public function expire(Subscription $subscription, \DateTimeImmutable $now): void
    {
        $subscription->setStatus(SubscriptionStatus::Expired);
        $this->doctrine->getManager()->flush();
        $this->logger->info('Subscription expired', ['id' => $subscription->getId()->toRfc4122()]);
    }

    public function getCurrentPlan(User $user): Plan
    {
        $sub = $this->getActiveSubscription($user);

        return $sub?->getPlan() ?? Plan::Free;
    }

    /**
     * Активная (usable) подписка пользователя или null. «usable» —
     * trialing/active/cancelled (последний — пока currentPeriodEnd не
     * наступил). Берём самую свежую по currentPeriodEnd на случай если
     * почему-то несколько usable одновременно (defense in depth).
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        $em = $this->doctrine->getManager();
        $rows = $em->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->andWhere('s.user = :u')
            ->andWhere('s.status IN (:usable)')
            ->setParameter('u', $user)
            ->setParameter('usable', [
                SubscriptionStatus::Trialing,
                SubscriptionStatus::Active,
                SubscriptionStatus::Cancelled,
            ])
            ->orderBy('s.currentPeriodEnd', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $rows[0] ?? null;
    }

    public function isRefundEligible(Subscription $subscription, \DateTimeImmutable $now): bool
    {
        if ($subscription->getPlan() === Plan::Free) {
            return false;
        }
        $diffDays = ($now->getTimestamp() - $subscription->getCurrentPeriodStart()->getTimestamp()) / 86400;

        return $diffDays < $this->catalog->refundWindowDays();
    }
}
