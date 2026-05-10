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
 * Уведомления вокруг триала: «3 дня до конца», «1 день до конца», «закончился».
 * Каждое уведомление дедуплицируется через subscriptions.notification_*_sent_at —
 * повторный тик scheduler'а не пришлёт сообщение второй раз.
 *
 * Quiet hours соблюдаем ТОЛЬКО для предупреждений (3д/1д) — если сейчас
 * ночь, оставляем sent_at пустым, на следующем тике попробуем снова.
 * Для «триал закончился» уведомление приходит независимо от quiet hours:
 * это уже свершившийся факт, откладывать смысла нет, и пользователь
 * после его получения может потерять смысл оплаты.
 *
 * Окна шире чем шаг scheduler'а на случай если worker лежал какое-то
 * время (3д = 60h..72h, 1д = 12h..24h). Дубль защищён sent_at-флагом.
 */
class TrialNotifier
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TelegramNotifierInterface $notifier,
        private readonly PlanCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Запустить все три проверки. Возвращает суммарное число отправленных
     * уведомлений — удобно для логов и smoke.
     */
    public function tick(\DateTimeImmutable $now): int
    {
        $sent = 0;
        $sent += $this->notifyThreeDayWarning($now);
        $sent += $this->notifyOneDayWarning($now);
        $sent += $this->notifyExpired($now);

        return $sent;
    }

    public function notifyThreeDayWarning(\DateTimeImmutable $now): int
    {
        $from = $now->modify('+60 hours');
        $to = $now->modify('+72 hours');

        $subs = $this->candidates(
            's.trialEndsAt >= :from AND s.trialEndsAt <= :to AND s.notification3dSentAt IS NULL',
            ['from' => $from, 'to' => $to],
            statuses: [SubscriptionStatus::Trialing],
        );

        $count = 0;
        foreach ($subs as $sub) {
            $user = $sub->getUser();
            if ($user->isQuietHoursNow($now)) {
                continue;
            }
            $proPrice = $this->catalog->priceRubMinor(Plan::Pro) / 100;
            $text = <<<TXT
                ⏰ Через 3 дня закончится бесплатный Pro.

                Дальше — Free ({$this->freeLimit()} действий/мес) или Pro (₽{$proPrice}/мес, без ограничений).

                Команда /upgrade появится скоро.
                TXT;
            if (!$this->send($sub, $text)) {
                continue;
            }
            $sub->setNotification3dSentAt($now);
            $count++;
        }
        if ($count > 0) {
            $this->doctrine->getManager()->flush();
        }

        return $count;
    }

    public function notifyOneDayWarning(\DateTimeImmutable $now): int
    {
        $from = $now->modify('+12 hours');
        $to = $now->modify('+24 hours');

        $subs = $this->candidates(
            's.trialEndsAt >= :from AND s.trialEndsAt <= :to AND s.notification1dSentAt IS NULL',
            ['from' => $from, 'to' => $to],
            statuses: [SubscriptionStatus::Trialing],
        );

        $count = 0;
        foreach ($subs as $sub) {
            $user = $sub->getUser();
            if ($user->isQuietHoursNow($now)) {
                continue;
            }
            $text = <<<TXT
                ⏰ Завтра закончится бесплатный Pro.

                С Free у тебя останется {$this->freeLimit()} действий/мес — все твои задачи и напоминания продолжат работать.
                TXT;
            if (!$this->send($sub, $text)) {
                continue;
            }
            $sub->setNotification1dSentAt($now);
            $count++;
        }
        if ($count > 0) {
            $this->doctrine->getManager()->flush();
        }

        return $count;
    }

    public function notifyExpired(\DateTimeImmutable $now): int
    {
        // trial_ends_at прошёл, но ещё не уведомляли. Quiet hours не
        // соблюдаем — уже свершившийся факт.
        $subs = $this->candidates(
            's.trialEndsAt IS NOT NULL AND s.trialEndsAt < :now AND s.notificationExpiredSentAt IS NULL',
            ['now' => $now],
            statuses: [SubscriptionStatus::Trialing, SubscriptionStatus::Expired],
        );

        $count = 0;
        foreach ($subs as $sub) {
            // Шлём только триалу (был status=trialing с trial_ends_at).
            // Для Pro истечения — отдельная логика в S5 (там auto-rebill).
            if ($sub->getTrialEndsAt() === null) {
                continue;
            }
            $text = <<<TXT
                🔚 Триал Pro закончился.

                Теперь ты на Free — {$this->freeLimit()} действий/мес. Все задачи и напоминания работают как обычно.

                Если нужны безлимитные действия — /upgrade (скоро).
                TXT;
            if (!$this->send($sub, $text)) {
                continue;
            }
            $sub->setNotificationExpiredSentAt($now);
            $count++;
        }
        if ($count > 0) {
            $this->doctrine->getManager()->flush();
        }

        return $count;
    }

    /**
     * @param list<SubscriptionStatus> $statuses
     * @param array<string, mixed> $params
     * @return list<Subscription>
     */
    private function candidates(string $where, array $params, array $statuses): array
    {
        $em = $this->doctrine->getManager();
        $qb = $em->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->where($where)
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', $statuses);
        foreach ($params as $k => $v) {
            $qb->setParameter($k, $v);
        }

        return $qb->getQuery()->getResult();
    }

    private function send(Subscription $sub, string $text): bool
    {
        $tg = $sub->getUser()->getTelegramId();
        if ($tg === null || $tg === '') {
            return false;
        }
        $ok = $this->notifier->sendMessage(chatId: (int) $tg, text: $text);
        if (!$ok) {
            $this->logger->warning('Trial notification failed', [
                'subscription_id' => $sub->getId()->toRfc4122(),
                'user_id' => $sub->getUser()->getId()->toRfc4122(),
            ]);
        }

        return $ok;
    }

    private function freeLimit(): int
    {
        return $this->catalog->actionLimit(Plan::Free);
    }
}
