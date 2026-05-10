<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Service\AccessGate;
use App\Service\TelegramUserResolver;
use App\Telegram\UI\UpgradeMessageBuilder;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Роутинг для callback'ов upgrade:* — три ветки:
 *   info  — soft-block из S2 ведёт сюда. Открывает полный экран /upgrade.
 *   pay   — заглушка «оплата скоро» (S4 заменит на ЮKassa-инвойс).
 *           Если кликнул админ — показываем админский текст вместо stub'а.
 *   later — пользователь сказал «не сейчас», убираем клавиатуру.
 */
class UpgradeCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly AccessGate $gate,
        private readonly UpgradeHandler $upgradeHandler,
        private readonly UpgradeMessageBuilder $builder,
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
        // Поведение прежнего UpgradeInfoCallbackHandler — открыть экран
        // /upgrade. Делегируем UpgradeHandler'у — он сам разберётся,
        // какой текст показать (free / trial / admin).
        ($this->upgradeHandler)($bot);
    }

    private function handlePay(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);

        // Если кнопку нажал админ (мог зайти из soft-block test'а) —
        // показываем админский текст, не stub.
        if ($this->gate->isAdmin($user)) {
            $bot->answerCallbackQuery();
            $payload = $this->builder->buildForAdmin($user);
            $bot->sendMessage(text: $payload['text']);

            return;
        }

        $this->logger->info('Upgrade pay clicked (stub)', [
            'user_id' => $user->getId()->toRfc4122(),
        ]);

        $bot->answerCallbackQuery();
        $bot->sendMessage(text: $this->builder->buildPayStub());
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
