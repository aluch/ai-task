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

        // Уже active Pro — не даём купить второй раз через /upgrade.
        // Для продления используется кнопка «💎 Продлить сейчас» в
        // /subscription → {@see sendRenewalInvoice}, она этот guard
        // обходит сознательно.
        $current = $this->subscriptions->getActiveSubscription($user);
        if ($current !== null && $current->getStatus() === SubscriptionStatus::Active) {
            $bot->answerCallbackQuery(
                text: 'У тебя уже Pro — всё ок. Продлить можно через /subscription.',
                show_alert: true,
            );

            return;
        }

        $bot->answerCallbackQuery();
        $this->sendInvoice($bot, $user);
    }

    /**
     * Отправить invoice для ручного renewal активной Pro-подписки.
     * Bypass'ит «уже active Pro» guard — это явное намерение
     * пользователя продлить ДО истечения.
     *
     * Вызывается из {@see SubscriptionCallbackHandler::handleRenew}
     * (callback `subscription:renew`).
     */
    public function sendRenewalInvoice(Nutgram $bot, \App\Entity\User $user): void
    {
        if ($this->gate->isAdmin($user)) {
            $payload = $this->builder->buildForAdmin($user);
            $bot->sendMessage(text: $payload['text']);

            return;
        }
        $this->sendInvoice($bot, $user);
    }

    /**
     * Общая логика отправки invoice. answerCallbackQuery должна быть
     * сделана вызывающим.
     */
    private function sendInvoice(Nutgram $bot, \App\Entity\User $user): void
    {
        // Платежи не сконфигурированы — admin/dev могут поднять стенд без
        // настоящих ключей. Не падать в HTTP-ошибку, а ответить честно.
        if (!$this->yooKassa->isConfigured()) {
            $this->logger->warning('Pay clicked but YooKassa not configured', [
                'user_id' => $user->getId()->toRfc4122(),
                'mode' => $this->yooKassa->getMode(),
            ]);
            $bot->sendMessage(
                text: '⚠️ Платежи пока не сконфигурированы на сервере. Попробуй чуть позже или напиши автору.',
            );

            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $invoicePayload = $this->invoice->buildPayload($user, $now);

        // provider_data НЕ передаём:
        //  - receipt-блок не нужен (самозанятый формирует чеки 54-ФЗ через
        //    «Мой налог» сам, см. S4 fix);
        //  - save_payment_method=true не работает: Telegram Payments не
        //    пробрасывает этот параметр в ЮKassa корректно, токен карты
        //    не сохраняется (см. docs/payments.md «Почему нет auto-rebill»).
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
