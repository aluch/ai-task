<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Subscription\SubscriptionStatus;
use App\Service\AccessGate;
use App\Service\Subscription\Provider\YooKassa\InvoicePayloadBuilder;
use App\Service\Subscription\Provider\YooKassa\YooKassaConfig;
use App\Service\Subscription\SubscriptionService;
use App\Service\TelegramUserResolver;
use App\Telegram\UI\UpgradeMessageBuilder;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Роутинг для callback'ов upgrade:* — три ветки:
 *   info  — soft-block из S2 ведёт сюда. Открывает полный экран /upgrade.
 *   pay   — отправляет ЮKassa-инвойс через Telegram Payments (S4).
 *           Если кликнул админ или у юзера уже Pro — отказ.
 *   later — пользователь сказал «не сейчас», убираем клавиатуру.
 */
class UpgradeCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly AccessGate $gate,
        private readonly UpgradeHandler $upgradeHandler,
        private readonly UpgradeMessageBuilder $builder,
        private readonly SubscriptionService $subscriptions,
        private readonly YooKassaConfig $yooKassa,
        private readonly InvoicePayloadBuilder $invoice,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, string $data): void
    {
        $action = explode(':', $data, 2)[0];

        match ($action) {
            'info' => $this->handleInfo($bot),
            'pay' => $this->handlePay($bot),
            'later' => $this->handleLater($bot),
            default => $bot->answerCallbackQuery(text: 'Неизвестное действие'),
        };
    }

    private function handleInfo(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();
        ($this->upgradeHandler)($bot);
    }

    private function handlePay(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);

        // Админ — не платит, безлимит.
        if ($this->gate->isAdmin($user)) {
            $bot->answerCallbackQuery();
            $payload = $this->builder->buildForAdmin($user);
            $bot->sendMessage(text: $payload['text']);

            return;
        }

        // Уже active Pro — не даём купить второй раз. Active важно отличить
        // от Cancelled-но-ещё-действует и от Trialing: при триале можно
        // купить (триал погасится в activatePro), при Cancelled тоже —
        // пользователь, возможно, передумал.
        $current = $this->subscriptions->getActiveSubscription($user);
        if ($current !== null && $current->getStatus() === SubscriptionStatus::Active) {
            $bot->answerCallbackQuery(
                text: 'У тебя уже Pro — всё ок.',
                show_alert: true,
            );

            return;
        }

        // Платежи не сконфигурированы — admin/dev могут поднять стенд без
        // настоящих ключей. Не падать в HTTP-ошибку, а ответить честно.
        if (!$this->yooKassa->isConfigured()) {
            $this->logger->warning('Pay clicked but YooKassa not configured', [
                'user_id' => $user->getId()->toRfc4122(),
                'mode' => $this->yooKassa->getMode(),
            ]);
            $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: '⚠️ Платежи пока не сконфигурированы на сервере. Попробуй чуть позже или напиши автору.',
            );

            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $invoicePayload = $this->invoice->buildPayload($user, $now);

        $bot->answerCallbackQuery();
        // provider_data НЕ передаём: для самозанятого ЮKassa не выступает
        // фискальным агентом — чеки 54-ФЗ владелец кабинета формирует сам
        // через «Мой налог». Если же передать receipt без customer.email/
        // phone, ЮKassa молча отклоняет invoice ещё до этапа списания, и
        // пользователь видит «Заплатить не получилось». См. InvoicePayloadBuilder::buildProviderData.
        $bot->sendInvoice(
            title: $this->invoice->getInvoiceTitle(),
            description: $this->invoice->getInvoiceDescription(),
            payload: $invoicePayload,
            provider_token: $this->yooKassa->getProviderToken(),
            currency: InvoicePayloadBuilder::CURRENCY,
            prices: $this->invoice->buildPrices(),
            start_parameter: 'pomni-pro',
        );

        $this->logger->info('Invoice sent', [
            'user_id' => $user->getId()->toRfc4122(),
            'mode' => $this->yooKassa->getMode(),
            'amount_minor' => $this->invoice->getAmountMinor(),
        ]);
    }

    private function handleLater(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($chatId !== null && $messageId !== null) {
            $bot->editMessageText(
                text: $this->builder->buildLaterAck(),
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: null,
            );
        }
    }
}
