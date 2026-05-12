<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

use App\Domain\Subscription\Plan;
use App\Entity\User;
use App\Service\PlanCatalog;

/**
 * Готовит payload (метаданные платежа в JSON) и provider_data (структура
 * для 54-ФЗ чека) для $bot->sendInvoice. Изолирован от Nutgram — чтобы
 * smoke-сценарии могли проверять корректность payload без поднятия бота.
 *
 * Лимит payload — 128 байт. Поэтому держим только critical: user_id (UUID,
 * 36 байт), plan, period_days, amount_minor, created_at.
 *
 * provider_data.receipt — для фискализации со стороны ЮKassa (если в ЛК
 * ЮKassa включена «Фискализация»). vat_code=1 = «без НДС» (самозанятый).
 */
final class InvoicePayloadBuilder
{
    public const DEFAULT_PRO_PERIOD_DAYS = 30;
    public const CURRENCY = 'RUB';

    public function __construct(
        private readonly PlanCatalog $catalog,
    ) {
    }

    public function getAmountMinor(): int
    {
        return $this->catalog->priceRubMinor(Plan::Pro);
    }

    /**
     * JSON-строка в payload (≤ 128 байт). Передаётся Telegram'у и
     * возвращается обратно в pre_checkout_query / successful_payment.
     *
     * $now пока в payload не пишем — UUID v7 уже несёт момент создания,
     * а лимит 128 байт не позволяет расточительности. Параметр оставлен
     * на случай если в S5 потребуется отметка времени для retry-логики.
     */
    public function buildPayload(User $user, \DateTimeImmutable $now): string
    {
        $payload = [
            'user_id' => $user->getId()->toRfc4122(),
            'plan' => Plan::Pro->value,
            'period_days' => self::DEFAULT_PRO_PERIOD_DAYS,
            'amount_minor' => $this->getAmountMinor(),
        ];
        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 128) {
            // Если кто-то расширит payload и упрётся в лимит — упадём
            // громко, чтобы поймать до сабмита в Telegram.
            throw new \LogicException(
                "Invoice payload exceeds Telegram's 128-byte limit: " . strlen($encoded) . ' bytes',
            );
        }

        return $encoded;
    }

    /**
     * @return array<int, array{label: string, amount: int}>
     */
    public function buildPrices(): array
    {
        return [
            [
                'label' => 'Pomni Pro (1 месяц)',
                'amount' => $this->getAmountMinor(),
            ],
        ];
    }

    /**
     * Цена в рублях для description / texts. Возвращает строку без
     * trailing ".00" — «490 ₽» а не «490.00 ₽».
     */
    public function getPriceRubLabel(): string
    {
        return number_format((int) round($this->getAmountMinor() / 100), 0, '.', ' ');
    }

    /**
     * NOT USED currently. Самозанятый формирует чеки 54-ФЗ через
     * приложение «Мой налог» вручную после получения денег — ЮKassa
     * в этом потоке не фискальный агент. Передача receipt'а без
     * customer.email/phone приводит к молчаливому отклонению invoice
     * (Telegram показывает «Заплатить не получилось», в логе ЮKassa
     * операция вообще не появляется).
     *
     * Метод оставлен для будущего перехода на ИП/ООО — тогда нужно
     * (а) включить фискализацию в ЛК ЮKassa и (б) передавать сюда
     * email/phone пользователя.
     */
    public function buildProviderData(?string $email = null): string
    {
        $rubles = number_format($this->getAmountMinor() / 100, 2, '.', '');
        $receipt = [
            'receipt' => [
                'items' => [[
                    'description' => 'Подписка Pomni Pro на 1 месяц',
                    'quantity' => '1.00',
                    'amount' => [
                        'value' => $rubles,
                        'currency' => self::CURRENCY,
                    ],
                    // vat_code=1 — «Без НДС» (самозанятый платит налог
                    // на профдоход, НДС не выделяется).
                    'vat_code' => 1,
                    'payment_subject' => 'service',
                    'payment_mode' => 'full_prepayment',
                ]],
            ],
        ];
        if ($email !== null && $email !== '') {
            $receipt['receipt']['customer'] = ['email' => $email];
        }

        return json_encode($receipt, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    public function getInvoiceTitle(): string
    {
        return 'Pomni Pro — 1 месяц';
    }

    public function getInvoiceDescription(): string
    {
        return '1500 действий в месяц, поддержка автора, все будущие интеграции.';
    }
}
