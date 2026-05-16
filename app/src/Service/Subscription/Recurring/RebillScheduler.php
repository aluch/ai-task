<?php

declare(strict_types=1);

namespace App\Service\Subscription\Recurring;

use App\Domain\Subscription\RecurringAttemptStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\RecurringAttempt;
use App\Entity\Subscription;
use App\Notification\TelegramNotifierInterface;
use App\Service\Subscription\Provider\YooKassa\InvoicePayloadBuilder;
use App\Service\Subscription\Provider\YooKassa\YooKassaApiClient;
use App\Service\Subscription\Provider\YooKassa\YooKassaApiException;
use App\Service\Subscription\Provider\YooKassa\YooKassaConfig;
use App\Service\Subscription\RenewalNotifier;
use App\Service\Subscription\SubscriptionService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Сердце S5 recurring billing. Запускается каждые 15 минут (см.
 * ReminderSchedule + TriggerRebillMessage). Каждый tick делает четыре
 * прохода:
 *
 *   1. notifyUpcoming — за 24±1 час до currentPeriodEnd шлём «завтра спишется».
 *   2. initiateCharges — за час до истечения создаём RecurringAttempt
 *      (attempt_number=1) и дёргаем ЮKassa API. Webhook прилетит позже.
 *   3. retryFailed — если последний attempt failed более 24 часов назад
 *      и attempt_number < 3 — новый attempt со следующим номером.
 *   4. expirePastDue — если attempt_number=3 failed более 24 часов назад —
 *      переводим подписку в expired.
 *
 * Все шаги идемпотентны: повторный tick через 15 минут не дублирует
 * effects. Защиту даёт notification24hBeforeRebillSentAt, существующие
 * pending-attempt'ы, рассматривание completed_at.
 */
class RebillScheduler
{
    /** Окно для notify-24h: за сколько часов ДО периода шлём (нижняя/верхняя). */
    private const NOTIFY_HOURS_BEFORE_MIN = 23;
    private const NOTIFY_HOURS_BEFORE_MAX = 25;

    /** Initiate-charge: за сколько минут до периода ВКЛЮЧАЕМ списание. */
    private const INITIATE_MIN_MINUTES_BEFORE = -60;
    private const INITIATE_MAX_MINUTES_BEFORE = 60;

    /** Между неудачными попытками — 24 часа. */
    private const RETRY_HOURS_INTERVAL = 24;
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionService $subscriptions,
        private readonly YooKassaApiClient $api,
        private readonly YooKassaConfig $config,
        private readonly InvoicePayloadBuilder $invoice,
        private readonly TelegramNotifierInterface $notifier,
        private readonly RenewalNotifier $renewalNotifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(\DateTimeImmutable $now): void
    {
        // Auto-rebill отключён — TG Payments не передаёт save_payment_method
        // в ЮKassa. Старые методы (notifyUpcomingCharges, initiateCharges,
        // retryFailedAttempts, expirePastDueSubscriptions) — dead code.
        //
        // Сейчас single responsibility: запустить RenewalNotifier — три
        // прохода для ручного renewal (3д/1д/expired). См. docs/payments.md.
        $this->logger->info('RebillScheduler tick (manual renewal mode)', [
            'now' => $now->format(\DateTimeInterface::ATOM),
        ]);
        $this->renewalNotifier->tick($now);
    }

    /**
     * DEAD CODE since 2026-05-16. Не вызывается из {@see run}. Оставлен
     * для возможного возврата к auto-rebill — см. docs/payments.md.
     *
     * Шаг 1 (был): подписки с auto_rebill_enabled, active-status,
     * currentPeriodEnd в окне [now+23h, now+25h], notification ещё не
     * отправлено → шлём уведомление и проставляем
     * notification_24h_before_rebill_sent_at.
     */
    public function notifyUpcomingCharges(\DateTimeImmutable $now): int
    {
        $em = $this->doctrine->getManager();
        $from = $now->modify('+' . self::NOTIFY_HOURS_BEFORE_MIN . ' hours');
        $to = $now->modify('+' . self::NOTIFY_HOURS_BEFORE_MAX . ' hours');

        $subs = $em->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->andWhere('s.status = :active')
            ->andWhere('s.autoRebillEnabled = true')
            ->andWhere('s.savedPaymentMethodId IS NOT NULL')
            ->andWhere('s.currentPeriodEnd BETWEEN :from AND :to')
            ->andWhere('s.notification24hBeforeRebillSentAt IS NULL')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($subs as $sub) {
            assert($sub instanceof Subscription);
            if ($this->sendUpcomingNotification($sub, $now)) {
                $sub->setNotification24hBeforeRebillSentAt($now);
                $em->flush();
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * DEAD CODE since 2026-05-16. Не вызывается из {@see run}.
     *
     * Шаг 2 (был): за час до currentPeriodEnd создавали RecurringAttempt
     * и дёргали ЮKassa createRecurringPayment. Невозможно без сохранённой
     * карты (Telegram Payments save_payment_method не работает).
     */
    public function initiateCharges(\DateTimeImmutable $now): int
    {
        $em = $this->doctrine->getManager();
        $from = $now->modify(sprintf('%+d minutes', self::INITIATE_MIN_MINUTES_BEFORE));
        $to = $now->modify(sprintf('%+d minutes', self::INITIATE_MAX_MINUTES_BEFORE));

        $subs = $em->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->andWhere('s.status = :active')
            ->andWhere('s.autoRebillEnabled = true')
            ->andWhere('s.savedPaymentMethodId IS NOT NULL')
            ->andWhere('s.currentPeriodEnd BETWEEN :from AND :to')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $started = 0;
        foreach ($subs as $sub) {
            assert($sub instanceof Subscription);
            if ($this->hasOpenAttempt($sub, $now)) {
                continue;
            }
            $nextAttemptNumber = $sub->getRebillFailedAttempts() + 1;
            if ($nextAttemptNumber > self::MAX_ATTEMPTS) {
                // Все попытки исчерпаны — ждём expirePastDue.
                continue;
            }
            if ($this->createAttemptAndCallApi($sub, $nextAttemptNumber, $now)) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * DEAD CODE since 2026-05-16. Не вызывается из {@see run}.
     *
     * Шаг 3 (был): failed attempts >24h назад и attempt_number<3 →
     * следующая попытка. Без recurring не имеет смысла.
     */
    public function retryFailedAttempts(\DateTimeImmutable $now): int
    {
        $em = $this->doctrine->getManager();
        $threshold = $now->modify('-' . self::RETRY_HOURS_INTERVAL . ' hours');

        // Последний attempt каждой подписки. DQL не умеет «last per group»
        // элегантно — пройдёмся по active-подпискам с rebillFailedAttempts ≥ 1.
        $subs = $em->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->andWhere('s.status = :active')
            ->andWhere('s.autoRebillEnabled = true')
            ->andWhere('s.savedPaymentMethodId IS NOT NULL')
            ->andWhere('s.rebillFailedAttempts BETWEEN 1 AND :maxMinus1')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('maxMinus1', self::MAX_ATTEMPTS - 1)
            ->getQuery()
            ->getResult();

        $retried = 0;
        foreach ($subs as $sub) {
            assert($sub instanceof Subscription);
            $last = $this->lastAttempt($sub);
            if ($last === null
                || $last->getStatus() !== RecurringAttemptStatus::Failed
                || $last->getCompletedAt() === null
                || $last->getCompletedAt() >= $threshold
            ) {
                continue;
            }
            if ($this->hasOpenAttempt($sub, $now)) {
                continue;
            }
            $nextAttemptNumber = $sub->getRebillFailedAttempts() + 1;
            if ($nextAttemptNumber > self::MAX_ATTEMPTS) {
                continue;
            }
            if ($this->createAttemptAndCallApi($sub, $nextAttemptNumber, $now)) {
                $retried++;
            }
        }

        return $retried;
    }

    /**
     * DEAD CODE since 2026-05-16. Не вызывается из {@see run}.
     *
     * Шаг 4 (был): подписки с 3-мя failed-attempt'ами + последняя
     * >24h назад → expired. Истечение paid-Pro теперь через обычный
     * {@see \App\Service\Subscription\SubscriptionExpirer::tick}, а
     * уведомление об истечении шлёт renewal-notifier (см. {@see run}).
     */
    public function expirePastDueSubscriptions(\DateTimeImmutable $now): int
    {
        $em = $this->doctrine->getManager();
        $threshold = $now->modify('-' . self::RETRY_HOURS_INTERVAL . ' hours');

        $subs = $em->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->andWhere('s.status = :active')
            ->andWhere('s.rebillFailedAttempts >= :max')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('max', self::MAX_ATTEMPTS)
            ->getQuery()
            ->getResult();

        $expired = 0;
        foreach ($subs as $sub) {
            assert($sub instanceof Subscription);
            $last = $this->lastAttempt($sub);
            if ($last === null
                || $last->getStatus() !== RecurringAttemptStatus::Failed
                || $last->getCompletedAt() === null
                || $last->getCompletedAt() >= $threshold
            ) {
                continue;
            }
            $this->subscriptions->expire($sub, $now);
            $this->notifyExpiredDueToFailedRebill($sub);
            $expired++;
        }

        return $expired;
    }

    private function hasOpenAttempt(Subscription $sub, \DateTimeImmutable $now): bool
    {
        $em = $this->doctrine->getManager();
        $pending = $em->getRepository(RecurringAttempt::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.subscription = :sub')
            ->andWhere('a.status = :pending')
            ->setParameter('sub', $sub)
            ->setParameter('pending', RecurringAttemptStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $pending > 0;
    }

    private function lastAttempt(Subscription $sub): ?RecurringAttempt
    {
        $em = $this->doctrine->getManager();
        $rows = $em->getRepository(RecurringAttempt::class)
            ->createQueryBuilder('a')
            ->andWhere('a.subscription = :sub')
            ->setParameter('sub', $sub)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $rows[0] ?? null;
    }

    private function createAttemptAndCallApi(
        Subscription $sub,
        int $attemptNumber,
        \DateTimeImmutable $now,
    ): bool {
        $em = $this->doctrine->getManager();
        $amount = $this->invoice->getAmountMinor();
        $attempt = new RecurringAttempt($sub, $attemptNumber, $amount, $now);
        $em->persist($attempt);
        $em->flush();

        $this->logger->info('Recurring attempt created', [
            'subscription_id' => $sub->getId()->toRfc4122(),
            'attempt_id' => $attempt->getId()->toRfc4122(),
            'attempt_number' => $attemptNumber,
            'amount_minor' => $amount,
        ]);

        try {
            $response = $this->api->createRecurringPayment(
                paymentMethodId: (string) $sub->getSavedPaymentMethodId(),
                amountMinor: $amount,
                description: 'Pomni Pro auto-renewal',
                idempotenceKey: $attempt->getIdempotenceKey()->toRfc4122(),
                metadata: [
                    'subscription_id' => $sub->getId()->toRfc4122(),
                    'attempt_id' => $attempt->getId()->toRfc4122(),
                ],
            );
        } catch (\Throwable $e) {
            // YooKassaApiException (4xx/5xx/transport) или LogicException
            // (API не сконфигурирован) — фиксируем attempt как failed
            // немедленно. Webhook не придёт. Catch \Throwable защищает
            // tick от полной остановки на одной кривой подписке.
            $this->logger->warning('Recurring attempt failed at API call', [
                'attempt_id' => $attempt->getId()->toRfc4122(),
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);
            $attempt->markFailed('api_error', $e->getMessage(), $now);
            $sub->setRebillFailedAttempts($sub->getRebillFailedAttempts() + 1);
            $em->flush();
            $this->notifyUserOnFailed($sub, $attemptNumber);

            return false;
        }

        // Logging остаётся в pending — реальный исход придёт через webhook.
        $this->logger->info('Recurring attempt dispatched to YooKassa', [
            'attempt_id' => $attempt->getId()->toRfc4122(),
            'remote_payment_id' => $response['id'] ?? null,
            'remote_status' => $response['status'] ?? null,
        ]);

        return true;
    }

    private function sendUpcomingNotification(Subscription $sub, \DateTimeImmutable $now): bool
    {
        $user = $sub->getUser();
        $tgId = $user->getTelegramId();
        if ($tgId === null) {
            return false;
        }
        $tz = new \DateTimeZone($user->getTimezone());
        $newPeriodEnd = $sub->getCurrentPeriodEnd()->modify('+' . InvoicePayloadBuilder::DEFAULT_PRO_PERIOD_DAYS . ' days');
        $newPeriodStr = $newPeriodEnd->setTimezone($tz)->format('d.m.Y');
        $rub = (int) round($this->invoice->getAmountMinor() / 100);
        $text = "⏰ Завтра спишется {$rub} ₽ за продление Pro\n\n"
            . "Подписка обновится автоматически до {$newPeriodStr}";

        return $this->notifier->sendMessage(
            chatId: (int) $tgId,
            text: $text,
            replyMarkup: [
                [['text' => '❌ Отключить автопродление', 'callback_data' => 'subscription:disable_rebill']],
            ],
        );
    }

    private function notifyUserOnFailed(Subscription $sub, int $attemptNumber): void
    {
        $tgId = $sub->getUser()->getTelegramId();
        if ($tgId === null) {
            return;
        }
        $rub = (int) round($this->invoice->getAmountMinor() / 100);
        if ($attemptNumber < self::MAX_ATTEMPTS) {
            $text = "⚠️ Не удалось списать {$rub} ₽ за продление Pro.\n\n"
                . 'Попробуем ещё раз завтра. Проверь карту в /subscription.';
        } else {
            $text = "❌ Не смогли списать {$rub} ₽ три раза.\n\n"
                . 'Подписка закроется через 24 часа. /upgrade чтобы поправить.';
        }
        $this->notifier->sendMessage(chatId: (int) $tgId, text: $text);
    }

    private function notifyExpiredDueToFailedRebill(Subscription $sub): void
    {
        $tgId = $sub->getUser()->getTelegramId();
        if ($tgId === null) {
            return;
        }
        $this->notifier->sendMessage(
            chatId: (int) $tgId,
            text: '🔚 Подписка Pro закрыта — не смогли списать оплату за продление.'
                . "\n\nВозобновить можно через /upgrade.",
        );
    }
}
