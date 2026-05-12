<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

use App\Entity\Payment;
use App\Entity\Subscription;

/**
 * Результат {@see PaymentProcessor::process}. Immutable DTO.
 *
 * idempotentSkip — true если payment с таким external_payment_id уже
 * был в БД; в этом случае payment/subscription — это найденные ранее
 * сущности, не новые. Используется handler'ом чтобы выбрать «всё уже
 * сделано, просто скажи юзеру что подписка активна» вместо «оплата
 * успешна 🎉».
 */
final class PaymentProcessResult
{
    public function __construct(
        public readonly bool $idempotentSkip,
        public readonly Payment $payment,
        public readonly ?Subscription $subscription,
    ) {
    }
}
