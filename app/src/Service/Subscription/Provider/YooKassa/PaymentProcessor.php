<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

use App\Domain\Subscription\PaymentStatus;
use App\Entity\Payment;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Subscription\SubscriptionService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Бизнес-логика successful_payment-callback: запись Payment в БД +
 * активация Pro. Изолирована от Nutgram чтобы быть smoke-тестируемой.
 *
 * Идемпотентна: повторный вызов с тем же external_payment_id вернёт
 * существующий Payment, без создания дубликата. Защита от
 * двойного callback'а Telegram (retries в их сторону + наша обработка
 * упала между insert и ack).
 */
class PaymentProcessor
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionService $subscriptions,
        private readonly InvoicePayloadBuilder $invoice,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return PaymentProcessResult
     */
    public function process(
        User $user,
        int $totalAmount,
        string $currency,
        string $invoicePayloadJson,
        string $providerPaymentChargeId,
        string $telegramPaymentChargeId,
        \DateTimeImmutable $now,
    ): PaymentProcessResult {
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Payment::class);

        // Идемпотентность: если такой external_payment_id уже есть —
        // возвращаем существующий результат без побочных эффектов.
        $existing = $repo->findOneBy(['externalPaymentId' => $providerPaymentChargeId]);
        if ($existing !== null) {
            $this->logger->info('Duplicate successful_payment ignored', [
                'external_payment_id' => $providerPaymentChargeId,
                'payment_id' => $existing->getId()->toRfc4122(),
            ]);

            return new PaymentProcessResult(
                idempotentSkip: true,
                payment: $existing,
                subscription: $existing->getSubscription(),
            );
        }

        // Период на 30 дней (S5 будет считать иначе через recurring).
        $periodEnd = $now->modify('+' . InvoicePayloadBuilder::DEFAULT_PRO_PERIOD_DAYS . ' days');

        $subscription = $this->subscriptions->activatePro(
            user: $user,
            periodStart: $now,
            periodEnd: $periodEnd,
            externalSubscriptionId: null,
            now: $now,
        );

        $payment = new Payment(
            user: $user,
            amountMinor: $totalAmount,
            status: PaymentStatus::Succeeded,
            createdAt: $now,
        );
        $payment->setCurrency($currency);
        $payment->setExternalPaymentId($providerPaymentChargeId);
        $payment->setSubscription($subscription);
        $payment->setProviderData([
            'telegram_payment_charge_id' => $telegramPaymentChargeId,
            'provider_payment_charge_id' => $providerPaymentChargeId,
            'invoice_payload' => $invoicePayloadJson,
        ]);

        $em->persist($payment);
        $em->flush();

        $this->logger->info('Payment processed and Pro activated', [
            'user_id' => $user->getId()->toRfc4122(),
            'payment_id' => $payment->getId()->toRfc4122(),
            'amount_minor' => $totalAmount,
            'period_end' => $periodEnd->format(\DateTimeInterface::ATOM),
        ]);

        return new PaymentProcessResult(
            idempotentSkip: false,
            payment: $payment,
            subscription: $subscription,
        );
    }
}
