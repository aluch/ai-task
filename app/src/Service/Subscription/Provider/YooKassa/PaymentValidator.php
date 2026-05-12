<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

use App\Domain\Subscription\Plan;
use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\User;
use App\Service\Subscription\SubscriptionService;

/**
 * Валидирует incoming pre_checkout_query от Telegram. Отдельный сервис
 * чтобы smoke-сценарии могли прогонять валидацию без поднятия Nutgram
 * (валидация — чистая функция от входных данных).
 *
 * Возвращает null если всё ок, или строку-причину если нельзя пропускать
 * платёж. Причина показывается пользователю через answerPreCheckoutQuery
 * (Telegram прокидывает её в UI). Из соображений безопасности — без
 * детализации (не «payload corrupted at byte 42», а «попробуй ещё раз»).
 */
class PaymentValidator
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * @return string|null null — пропускаем; string — отказ + причина
     */
    public function validatePreCheckout(
        string $invoicePayloadJson,
        int $totalAmount,
        string $currency,
        ?User $user,
    ): ?string {
        if ($user === null) {
            return 'Пользователь не найден, попробуй ещё раз через /upgrade';
        }
        if ($invoicePayloadJson === '') {
            return 'Платёж невозможен, попробуй ещё раз через /upgrade';
        }

        $data = json_decode($invoicePayloadJson, true);
        if (
            !is_array($data)
            || !isset($data['user_id'], $data['amount_minor'])
            || !is_string($data['user_id'])
            || !is_int($data['amount_minor'])
        ) {
            return 'Платёж невозможен, попробуй ещё раз через /upgrade';
        }

        // user_id в payload — UUID юзера, которому отправлен invoice. Защита
        // от forward'нутого invoice'а в другой чат.
        if ($data['user_id'] !== $user->getId()->toRfc4122()) {
            return 'Платёж не для этого аккаунта';
        }

        // Сумма в payload должна совпадать с total_amount от Telegram —
        // защита от подмены цены на стороне клиента.
        if ($data['amount_minor'] !== $totalAmount) {
            return 'Сумма не совпадает, попробуй ещё раз через /upgrade';
        }

        if ($currency !== InvoicePayloadBuilder::CURRENCY) {
            return 'Поддерживается только рубль';
        }

        // Активная Pro — повторный платёж не допускаем (избегаем dual-charge).
        // На триале/cancelled — можно купить, активируется поверх.
        $active = $this->subscriptions->getActiveSubscription($user);
        if (
            $active !== null
            && $active->getStatus() === SubscriptionStatus::Active
            && $active->getPlan() === Plan::Pro
        ) {
            return 'У тебя уже активна Pro-подписка';
        }

        return null;
    }
}
