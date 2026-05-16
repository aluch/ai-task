<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Статус одной попытки recurring-списания (см. {@see \App\Entity\RecurringAttempt}).
 *
 * Pending — отправлен запрос в ЮKassa, ждём webhook.
 * Succeeded — webhook payment.succeeded получен, Payment + продление подписки записаны.
 * Failed — webhook payment.canceled/failed получен или таймаут.
 */
enum RecurringAttemptStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
