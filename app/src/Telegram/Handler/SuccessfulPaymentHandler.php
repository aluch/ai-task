<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\User;
use App\Service\Subscription\AdminPaymentNotifier;
use App\Service\Subscription\Provider\YooKassa\PaymentProcessor;
use App\Service\Subscription\Provider\YooKassa\YooKassaConfig;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Обрабатывает successful_payment от Telegram — финальное подтверждение
 * списания. Списание уже произошло, наша задача — корректно зафиксировать
 * Payment и активировать Pro.
 *
 * Идемпотентность критична: Telegram может ретраить callback, наш
 * обработчик может упасть между insert и ответом. {@see PaymentProcessor}
 * гарантирует что повторный external_payment_id даёт no-op.
 */
class SuccessfulPaymentHandler
{
    public function __construct(
        private readonly PaymentProcessor $processor,
        private readonly ManagerRegistry $doctrine,
        private readonly YooKassaConfig $yooKassa,
        private readonly AdminPaymentNotifier $adminNotifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $payment = $bot->message()?->successful_payment;
        if ($payment === null) {
            return;
        }

        $telegramFromId = $bot->message()?->from?->id;
        if ($telegramFromId === null) {
            $this->logger->error('successful_payment without from.id');

            return;
        }

        $em = $this->doctrine->getManager();
        $user = $em->getRepository(User::class)
            ->findOneBy(['telegramId' => (string) $telegramFromId]);

        if ($user === null) {
            // User должен быть — pre_checkout уже проверял. Если такой
            // редкий случай — сохраняем платёж осиротевшим? Нет: лучше
            // громко логировать, деньги юзер вернёт через support.
            $this->logger->error('successful_payment for unknown telegram_id', [
                'telegram_id' => $telegramFromId,
                'amount' => $payment->total_amount,
                'provider_payment_charge_id' => $payment->provider_payment_charge_id,
            ]);
            $bot->sendMessage(
                text: '⚠️ Что-то пошло не так с привязкой платежа. Напиши /admin или автору.',
            );

            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = $this->processor->process(
            user: $user,
            totalAmount: $payment->total_amount,
            currency: $payment->currency,
            invoicePayloadJson: $payment->invoice_payload,
            providerPaymentChargeId: $payment->provider_payment_charge_id,
            telegramPaymentChargeId: $payment->telegram_payment_charge_id,
            now: $now,
        );

        if ($result->idempotentSkip) {
            $bot->sendMessage(
                text: '✅ Платёж уже обработан, твоя подписка активна. Статус: /subscription',
            );

            return;
        }

        $periodEnd = $result->subscription?->getCurrentPeriodEnd();
        $untilStr = $periodEnd !== null
            ? $periodEnd->setTimezone(new \DateTimeZone($user->getTimezone()))->format('d.m.Y')
            : '—';

        $bot->sendMessage(
            text: "🎉 Спасибо! Подписка Pomni Pro активирована до {$untilStr}.\n\n"
                . "Теперь у тебя 1500 действий в месяц без ограничений.\n\n"
                . 'Статус: /subscription',
        );

        // Уведомить админа о новой оплате — только в live. В test —
        // спамил бы при каждом тестировании, не нужно.
        if (!$this->yooKassa->isTestMode()) {
            $this->adminNotifier->notifyPaid($user, $result->payment);
        }
    }
}
