<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Domain\Subscription\Plan;
use App\Entity\UsageCounter;
use App\Entity\User;
use App\Service\PlanCatalog;
use Doctrine\Persistence\ManagerRegistry;

class UsageTracker
{
    /** Длина скользящего окна Free-тарифа в секундах (30 дней). */
    private const FREE_WINDOW_SECONDS = 30 * 86400;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionService $subscriptions,
        private readonly PlanCatalog $catalog,
    ) {
    }

    public function recordAction(User $user, \DateTimeImmutable $now): UsageCounter
    {
        // Админ — безлимитен и счётчик не нужен. Возвращаем dummy-инстанс,
        // чтобы вызов был no-op даже если кто-то в коде забудет проверить
        // isAdmin до record'а. Защита in depth.
        if ($user->isAdmin()) {
            return new UsageCounter($user);
        }

        $counter = $this->ensureCounter($user);
        $plan = $this->subscriptions->getCurrentPlan($user);

        if ($plan === Plan::Free) {
            $this->ensureFreePeriod($counter, $now);
            $counter->setFreeActionsCount($counter->getFreeActionsCount() + 1);
        } else {
            $this->ensureProPeriod($counter, $user);
            $counter->setProActionsCount($counter->getProActionsCount() + 1);
        }

        $this->doctrine->getManager()->flush();

        return $counter;
    }

    public function getRemainingActions(User $user, \DateTimeImmutable $now): int
    {
        // Админ — безлимитен. Большое число (PHP_INT_MAX) скорее введёт в
        // заблуждение в UI; вернём заведомо «много» = миллион.
        if ($user->isAdmin()) {
            return 1_000_000;
        }

        $counter = $this->ensureCounter($user);
        $plan = $this->subscriptions->getCurrentPlan($user);
        $limit = $this->catalog->actionLimit($plan);

        if ($plan === Plan::Free) {
            $this->ensureFreePeriod($counter, $now);
            $used = $counter->getFreeActionsCount();
        } else {
            $this->ensureProPeriod($counter, $user);
            $used = $counter->getProActionsCount();
        }
        $this->doctrine->getManager()->flush();

        return max(0, $limit - $used);
    }

    public function canPerformAction(User $user, \DateTimeImmutable $now): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->getRemainingActions($user, $now) > 0;
    }

    /**
     * Когда сбросится счётчик — для UX («лимит обновится 17 мая»).
     * Free: freePeriodStart + 30 дней. Pro: currentPeriodEnd подписки.
     */
    public function getNextResetAt(User $user, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $plan = $this->subscriptions->getCurrentPlan($user);
        if ($plan === Plan::Free) {
            $counter = $this->ensureCounter($user);
            $start = $counter->getFreePeriodStart() ?? $now;

            return $start->modify('+30 days');
        }
        $sub = $this->subscriptions->getActiveSubscription($user);

        return $sub?->getCurrentPeriodEnd();
    }

    private function ensureCounter(User $user): UsageCounter
    {
        $em = $this->doctrine->getManager();
        $counter = $em->getRepository(UsageCounter::class)->findOneBy(['user' => $user]);
        if ($counter !== null) {
            return $counter;
        }
        $counter = new UsageCounter($user);
        $em->persist($counter);
        $em->flush();

        return $counter;
    }

    /**
     * Free: скользящее 30-дневное окно от первого действия. Если окно
     * истекло — counter сбрасывается, новый freePeriodStart = now.
     */
    private function ensureFreePeriod(UsageCounter $counter, \DateTimeImmutable $now): void
    {
        $start = $counter->getFreePeriodStart();
        if ($start === null) {
            $counter->setFreePeriodStart($now);

            return;
        }
        if (($now->getTimestamp() - $start->getTimestamp()) >= self::FREE_WINDOW_SECONDS) {
            $counter->setFreeActionsCount(0);
            $counter->setFreePeriodStart($now);
        }
    }

    /**
     * Pro: период привязан к currentPeriodStart активной подписки. Если
     * в counter'е другой период — сбросить и поставить актуальный.
     */
    private function ensureProPeriod(UsageCounter $counter, User $user): void
    {
        $sub = $this->subscriptions->getActiveSubscription($user);
        if ($sub === null) {
            return;
        }
        $subStart = $sub->getCurrentPeriodStart();
        $counterStart = $counter->getProPeriodStart();
        if ($counterStart === null || $counterStart->getTimestamp() !== $subStart->getTimestamp()) {
            $counter->setProActionsCount(0);
            $counter->setProPeriodStart($subStart);
        }
    }
}
