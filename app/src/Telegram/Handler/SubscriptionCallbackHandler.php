<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Service\TelegramUserResolver;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Callback'и subscription:* — текущая версия (после отката S5 auto-rebill).
 *
 *   subscription:renew — пользователь нажал «💎 Продлить сейчас» из
 *                        /subscription для active-подписки. Открываем
 *                        стандартный /upgrade flow (через UpgradeHandler).
 *                        Новый платёж активирует Pro заново со сдвигом
 *                        currentPeriodEnd.
 *
 * Старые S3-callback'и `subscription:cancel:*` и S5-callback'и
 * `subscription:disable_rebill:*` / `enable_rebill` удалены вместе с
 * auto-rebill flow'ом. См. docs/payments.md «Почему нет auto-rebill».
 */
class SubscriptionCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly UpgradeCallbackHandler $upgrade,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, string $data): void
    {
        $action = explode(':', $data, 2)[0];

        match ($action) {
            'renew' => $this->handleRenew($bot),
            default => $bot->answerCallbackQuery(text: 'Неизвестное действие'),
        };
    }

    private function handleRenew(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $user = $this->userResolver->resolve($bot);
        $this->logger->info('Manual renew clicked', [
            'user_id' => $user->getId()->toRfc4122(),
        ]);

        // sendRenewalInvoice bypass'ит «уже active Pro» guard в
        // UpgradeCallbackHandler::handlePay — это явное намерение
        // юзера продлить ДО истечения. activatePro в SuccessfulPaymentHandler
        // сдвинет currentPeriodEnd, после чего цикл уведомлений и
        // SubscriptionExpirer заработают со свежими датами.
        $this->upgrade->sendRenewalInvoice($bot, $user);
    }
}
