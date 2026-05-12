<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Payment;
use App\Entity\User;
use App\Notification\TelegramNotifierInterface;
use App\Service\AccessGate;
use App\Service\Subscription\Provider\YooKassa\YooKassaConfig;
use Psr\Log\LoggerInterface;

/**
 * Шлёт админу короткое сообщение про новый успешный платёж — для
 * субъективной уверенности «деньги пришли» в первые месяцы биллинга.
 *
 * В test-режиме no-op: иначе мы засыпали бы себя уведомлениями при
 * каждом прогоне smoke / тестовой проверке Telegram Payments. Решение
 * остаётся за вызывающим — handler сам решает дёргать или нет —
 * но как defense-in-depth внутри тоже проверяем mode.
 */
class AdminPaymentNotifier
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly TelegramNotifierInterface $notifier,
        private readonly YooKassaConfig $yooKassa,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyPaid(User $payer, Payment $payment): void
    {
        if ($this->yooKassa->isTestMode()) {
            return;
        }

        $adminTgId = $this->gate->adminTelegramId();
        if ($adminTgId === '') {
            $this->logger->warning('AdminPaymentNotifier: ADMIN_TELEGRAM_ID не задан');

            return;
        }

        $rubles = number_format($payment->getAmountMinor() / 100, 2, '.', ' ');
        $name = $payer->getName() ?? '?';
        $tgId = $payer->getTelegramId() ?? '?';
        $paymentId = $payment->getId()->toRfc4122();
        $externalId = $payment->getExternalPaymentId() ?? '?';

        $text = "💰 Новый платёж\n\n"
            . "{$name} (tg:{$tgId}) — {$rubles} {$payment->getCurrency()}\n"
            . "payment_id: {$paymentId}\n"
            . "external_id: {$externalId}";

        $this->notifier->sendMessage(
            chatId: (int) $adminTgId,
            text: $text,
        );
    }
}
