<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Domain\Subscription\Plan;
use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\Subscription;
use App\Notification\TelegramNotifierInterface;
use App\Service\PlanCatalog;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Уведомления вокруг истечения платной Pro-подписки в модели «manual renewal»
 * (auto-rebill отключён, см. docs/payments.md). Триггерится из RebillScheduler.
 *
 * Три прохода (все идемпотентны через notification_*_renewal_sent_at /
 * notification_expired_sent_at):
 *   - notify3DaysBefore   — currentPeriodEnd в [now+60h, now+72h]
 *   - notify1DayBefore    — currentPeriodEnd в [now+12h, now+24h]
 *   - notifyExpiredAndExpire — currentPeriodEnd < now, status=Active,
 *                              plan=Pro, не trial (trialEndsAt IS NULL) →
 *                              шлём «закончилось» + переводим в Expired.
 *
 * Quiet hours соблюдаем только для предупреждений (3д/1д). «Закончилось» —
 * свершившийся факт, шлём независимо от часа.
 *
 * Отличие от TrialNotifier:
 *   - TrialNotifier фильтрует по статусу Trialing и notification_3d_sent_at;
 *   - RenewalNotifier фильтрует по Active + plan=Pro + не-trial +
 *     notification_3d_renewal_sent_at.
 * Поля раздельные → нет коллизии между триальным циклом и paid-renewal.
 */
class RenewalNotifier
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TelegramNotifierInterface $notifier,
        private readonly PlanCatalog $catalog,
        private readonly SubscriptionService $subscriptions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function tick(\DateTimeImmutable $now): int
    {
        $sent = 0;
        $sent += $this->notifyThreeDaysBefore($now);
        $sent += $this->notifyOneDayBefore($now);
        $sent += $this->notifyExpiredAndExpire($now);

        return $sent;
    }

    public function notifyThreeDaysBefore(\DateTimeImmutable $now): int
    {
        $from = $now->modify('+60 hours');
        $to = $now->modify('+72 hours');

        $em = $this->doctrine->getManager();
        $subs = $em->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->andWhere('s.status = :active')
            ->andWhere('s.plan = :pro')
            ->andWhere('s.trialEndsAt IS NULL')
            ->andWhere('s.currentPeriodEnd BETWEEN :from AND :to')
            ->andWhere('s.notification3dRenewalSentAt IS NULL')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('pro', Plan::Pro)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($subs as $sub) {
            assert($sub instanceof Subscription);
            if ($sub->getUser()->isQuietHoursNow($now)) {
                continue;
            }
            $text = $this->build3dText($sub);
            if (!$this->send($sub, $text)) {
                continue;
            }
            $sub->setNotification3dRenewalSentAt($now);
            $count++;
        }
        if ($count > 0) {
            $em->flush();
        }

        return $count;
    }

    public function notifyOneDayBefore(\DateTimeImmutable $now): int
    {
        $from = $now->modify('+12 hours');
        $to = $now->modify('+24 hours');

        $em = $this->doctrine->getManager();
        $subs = $em->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->andWhere('s.status = :active')
            ->andWhere('s.plan = :pro')
            ->andWhere('s.trialEndsAt IS NULL')
            ->andWhere('s.currentPeriodEnd BETWEEN :from AND :to')
            ->andWhere('s.notification1dRenewalSentAt IS NULL')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('pro', Plan::Pro)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($subs as $sub) {
            assert($sub instanceof Subscription);
            if ($sub->getUser()->isQuietHoursNow($now)) {
                continue;
            }
            $text = $this->build1dText($sub);
            if (!$this->send($sub, $text)) {
                continue;
            }
            $sub->setNotification1dRenewalSentAt($now);
            $count++;
        }
        if ($count > 0) {
            $em->flush();
        }

        return $count;
    }

    /**
     * Истёкшие подписки: переводим в Expired и шлём уведомление.
     * SubscriptionExpirer также переводит истёкшие в Expired независимо —
     * мы здесь срабатываем раньше (чтобы успеть уведомить до того как
     * expirer обнулит статус), но обработчики идемпотентны: если expirer
     * уже отработал, наш фильтр по Active просто не найдёт ничего, и
     * notification_expired_sent_at останется NULL — на следующий tick
     * мы поднимем подписку статусом Expired (см. fallback ниже).
     */
    public function notifyExpiredAndExpire(\DateTimeImmutable $now): int
    {
        $em = $this->doctrine->getManager();

        // Шаг 1: active+plan=Pro+не-trial+period_end<now → уведомить и expire.
        $subs = $em->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->andWhere('s.status = :active')
            ->andWhere('s.plan = :pro')
            ->andWhere('s.trialEndsAt IS NULL')
            ->andWhere('s.currentPeriodEnd < :now')
            ->andWhere('s.notificationExpiredSentAt IS NULL')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('pro', Plan::Pro)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($subs as $sub) {
            assert($sub instanceof Subscription);
            $text = $this->buildExpiredText();
            if (!$this->send($sub, $text)) {
                continue;
            }
            $sub->setNotificationExpiredSentAt($now);
            $this->subscriptions->expire($sub, $now);
            $count++;
        }

        // Шаг 2 (fallback): если SubscriptionExpirer уже перевёл в Expired
        // до нас (не-trial Pro, notification_expired_sent_at NULL) — всё
        // равно отправим уведомление.
        $expired = $em->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->andWhere('s.status = :expired')
            ->andWhere('s.plan = :pro')
            ->andWhere('s.trialEndsAt IS NULL')
            ->andWhere('s.notificationExpiredSentAt IS NULL')
            ->andWhere('s.currentPeriodEnd < :now')
            ->setParameter('expired', SubscriptionStatus::Expired)
            ->setParameter('pro', Plan::Pro)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        foreach ($expired as $sub) {
            assert($sub instanceof Subscription);
            $text = $this->buildExpiredText();
            if (!$this->send($sub, $text)) {
                continue;
            }
            $sub->setNotificationExpiredSentAt($now);
            $count++;
        }

        if ($count > 0) {
            $em->flush();
            $this->logger->info('Paid Pro expiry notifications sent', ['count' => $count]);
        }

        return $count;
    }

    private function build3dText(Subscription $sub): string
    {
        $tz = new \DateTimeZone($sub->getUser()->getTimezone());
        $until = $sub->getCurrentPeriodEnd()->setTimezone($tz)->format('d.m.Y');

        return "⏰ Через 3 дня закончится Pro\n\n"
            . "Подписка истекает {$until}.\n"
            . 'Чтобы продолжить — /upgrade';
    }

    private function build1dText(Subscription $sub): string
    {
        $tz = new \DateTimeZone($sub->getUser()->getTimezone());
        $until = $sub->getCurrentPeriodEnd()->setTimezone($tz)->format('d.m.Y HH:mm');

        return "⏰ Завтра закончится Pro\n\n"
            . "Подписка истекает {$until}.\n"
            . 'Чтобы не потерять доступ — /upgrade';
    }

    private function buildExpiredText(): string
    {
        $freeLimit = $this->catalog->actionLimit(Plan::Free);

        return "🔚 Подписка Pro закончилась\n\n"
            . "Ты на тарифе Free ({$freeLimit} действий/мес).\n"
            . 'Чтобы возобновить — /upgrade';
    }

    private function send(Subscription $sub, string $text): bool
    {
        $tg = $sub->getUser()->getTelegramId();
        if ($tg === null || $tg === '') {
            return false;
        }
        $ok = $this->notifier->sendMessage(chatId: (int) $tg, text: $text);
        if (!$ok) {
            $this->logger->warning('Renewal notification failed', [
                'subscription_id' => $sub->getId()->toRfc4122(),
                'user_id' => $sub->getUser()->getId()->toRfc4122(),
            ]);
        }

        return $ok;
    }
}
