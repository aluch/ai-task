<?php

declare(strict_types=1);

namespace App\Service\Subscription\Recurring;

use App\Domain\Subscription\PaymentStatus;
use App\Domain\Subscription\RecurringAttemptStatus;
use App\Entity\Payment;
use App\Entity\RecurringAttempt;
use App\Notification\TelegramNotifierInterface;
use App\Service\Subscription\AdminPaymentNotifier;
use App\Service\Subscription\Provider\YooKassa\InvoicePayloadBuilder;
use App\Service\Subscription\Provider\YooKassa\YooKassaConfig;
use App\Service\Subscription\SubscriptionService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Обрабатывает входящий webhook от ЮKassa («payment.succeeded» /
 * «payment.canceled» / «payment.waiting_for_capture») по нашему
 * RecurringAttempt'у.
 *
 * Идемпотентен:
 *  - если attempt уже не Pending — no-op (повторный webhook);
 *  - если attempt не найден по metadata.attempt_id — log + no-op (200 OK
 *    обратно, иначе ЮKassa спамит retry'ями).
 *
 * Изолирован от HTTP-слоя для smoke-тестирования.
 */
class RebillWebhookProcessor
{
    public const RESULT_OK = 'ok';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_UNKNOWN_ATTEMPT = 'unknown_attempt';
    public const RESULT_IGNORED_EVENT = 'ignored_event';
    public const RESULT_MALFORMED = 'malformed';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionService $subscriptions,
        private readonly InvoicePayloadBuilder $invoice,
        private readonly TelegramNotifierInterface $notifier,
        private readonly AdminPaymentNotifier $adminNotifier,
        private readonly YooKassaConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload — распарсенный JSON от ЮKassa
     */
    public function process(array $payload, \DateTimeImmutable $now): string
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        $object = $payload['object'] ?? null;
        if (!is_array($object)) {
            $this->logger->warning('YooKassa webhook malformed (no object)', ['payload' => $payload]);

            return self::RESULT_MALFORMED;
        }

        $attemptId = $object['metadata']['attempt_id'] ?? null;
        if (!is_string($attemptId) || !Uuid::isValid($attemptId)) {
            $this->logger->warning('YooKassa webhook without valid attempt_id metadata', [
                'event' => $event,
                'payment_id' => $object['id'] ?? null,
            ]);

            return self::RESULT_UNKNOWN_ATTEMPT;
        }

        $em = $this->doctrine->getManager();
        $attempt = $em->getRepository(RecurringAttempt::class)->find(Uuid::fromString($attemptId));
        if ($attempt === null) {
            $this->logger->warning('YooKassa webhook: attempt not found', [
                'attempt_id' => $attemptId,
                'event' => $event,
            ]);

            return self::RESULT_UNKNOWN_ATTEMPT;
        }

        // Идемпотентность: если уже не Pending — no-op. Защита от повторных
        // webhook'ов от ЮKassa.
        if ($attempt->getStatus() !== RecurringAttemptStatus::Pending) {
            $this->logger->info('YooKassa webhook ignored (attempt not pending)', [
                'attempt_id' => $attemptId,
                'current_status' => $attempt->getStatus()->value,
            ]);

            return self::RESULT_DUPLICATE;
        }

        return match ($event) {
            'payment.succeeded' => $this->handleSucceeded($attempt, $object, $now),
            'payment.canceled' => $this->handleFailed($attempt, $object, $now),
            default => $this->ignoreEvent($event, $attemptId),
        };
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handleSucceeded(RecurringAttempt $attempt, array $object, \DateTimeImmutable $now): string
    {
        $em = $this->doctrine->getManager();
        $sub = $attempt->getSubscription();
        $externalId = (string) ($object['id'] ?? '');
        if ($externalId === '') {
            $this->logger->warning('payment.succeeded without object.id', [
                'attempt_id' => $attempt->getId()->toRfc4122(),
            ]);

            return self::RESULT_MALFORMED;
        }

        $attempt->markSucceeded($externalId, $now);

        // Продление: новый period = старый currentPeriodEnd + 30 дней.
        // Берём именно от старого periodEnd, а не от now — чтобы пользователь
        // не терял часы из-за нашего lag'а.
        $oldEnd = $sub->getCurrentPeriodEnd();
        $newStart = $oldEnd;
        $newEnd = $oldEnd->modify('+' . InvoicePayloadBuilder::DEFAULT_PRO_PERIOD_DAYS . ' days');

        $subscription = $this->subscriptions->activatePro(
            user: $sub->getUser(),
            periodStart: $newStart,
            periodEnd: $newEnd,
            externalSubscriptionId: null,
            now: $now,
        );
        // Сброс счётчика и notification flag для следующего цикла.
        $subscription->setRebillFailedAttempts(0);
        $subscription->setNotification24hBeforeRebillSentAt(null);

        $payment = new Payment(
            user: $sub->getUser(),
            amountMinor: $attempt->getAmountMinor(),
            status: PaymentStatus::Succeeded,
            createdAt: $now,
        );
        $payment->setCurrency(InvoicePayloadBuilder::CURRENCY);
        $payment->setExternalPaymentId($externalId);
        $payment->setSubscription($subscription);
        $payment->setProviderData([
            'recurring' => true,
            'attempt_id' => $attempt->getId()->toRfc4122(),
            'attempt_number' => $attempt->getAttemptNumber(),
        ]);

        $em->persist($payment);
        $em->flush();

        $this->logger->info('Recurring payment succeeded', [
            'attempt_id' => $attempt->getId()->toRfc4122(),
            'payment_id' => $payment->getId()->toRfc4122(),
            'subscription_id' => $subscription->getId()->toRfc4122(),
            'new_period_end' => $newEnd->format(\DateTimeInterface::ATOM),
        ]);

        $this->notifyUserSucceeded($subscription, $newEnd);
        if (!$this->config->isTestMode()) {
            $this->adminNotifier->notifyPaid($sub->getUser(), $payment);
        }

        return self::RESULT_OK;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function handleFailed(RecurringAttempt $attempt, array $object, \DateTimeImmutable $now): string
    {
        $em = $this->doctrine->getManager();
        $sub = $attempt->getSubscription();

        $code = $object['cancellation_details']['reason'] ?? null;
        $description = $object['cancellation_details']['party'] ?? null;
        $attempt->markFailed(
            is_string($code) ? $code : null,
            is_string($description) ? $description : null,
            $now,
        );
        $sub->setRebillFailedAttempts($sub->getRebillFailedAttempts() + 1);
        $em->flush();

        $this->logger->info('Recurring payment failed', [
            'attempt_id' => $attempt->getId()->toRfc4122(),
            'attempt_number' => $attempt->getAttemptNumber(),
            'subscription_failed_count' => $sub->getRebillFailedAttempts(),
            'reason' => $code,
        ]);

        $this->notifyUserFailed($sub, $attempt->getAttemptNumber());

        return self::RESULT_OK;
    }

    private function ignoreEvent(string $event, string $attemptId): string
    {
        $this->logger->info('YooKassa webhook ignored (unknown event)', [
            'event' => $event,
            'attempt_id' => $attemptId,
        ]);

        return self::RESULT_IGNORED_EVENT;
    }

    private function notifyUserSucceeded(\App\Entity\Subscription $sub, \DateTimeImmutable $newEnd): void
    {
        $tgId = $sub->getUser()->getTelegramId();
        if ($tgId === null) {
            return;
        }
        $tz = new \DateTimeZone($sub->getUser()->getTimezone());
        $rub = (int) round($this->invoice->getAmountMinor() / 100);
        $until = $newEnd->setTimezone($tz)->format('d.m.Y');
        $this->notifier->sendMessage(
            chatId: (int) $tgId,
            text: "✅ Подписка Pomni Pro продлена до {$until}. Списано {$rub} ₽.\n\nСтатус: /subscription",
        );
    }

    private function notifyUserFailed(\App\Entity\Subscription $sub, int $attemptNumber): void
    {
        $tgId = $sub->getUser()->getTelegramId();
        if ($tgId === null) {
            return;
        }
        $rub = (int) round($this->invoice->getAmountMinor() / 100);
        $isLast = $attemptNumber >= 3;
        $text = $isLast
            ? "❌ Не смогли списать {$rub} ₽ — три попытки подряд.\n\n"
                . 'Подписка закроется через 24 часа. /upgrade чтобы сменить карту.'
            : "⚠️ Не удалось списать {$rub} ₽ за продление Pro.\n\n"
                . 'Попробуем ещё раз через 24 часа. Проверь карту: возможно недостаточно средств или истёк срок.';
        $this->notifier->sendMessage(chatId: (int) $tgId, text: $text);
    }
}
