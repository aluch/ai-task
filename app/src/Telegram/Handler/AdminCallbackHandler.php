<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Service\AccessGate;
use App\Service\TelegramUserResolver;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Callback'и admin:* — подтверждения для админских операций над
 * подписками. Формат:
 *   admin:grant_trial:<tg_id>:confirm|abort
 *   admin:grant_pro:<tg_id>:<days>:confirm|abort
 *   admin:revoke_subscription:<tg_id>:confirm|abort
 *
 * Доступ — только для админа. AdminHandler::perform* делает реальные
 * мутации (мы их переиспользуем чтобы тексты ответов не дублировать).
 */
class AdminCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly AccessGate $gate,
        private readonly AdminHandler $adminHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, string $data): void
    {
        $user = $this->userResolver->resolve($bot);
        if (!$this->gate->isAdmin($user)) {
            $bot->answerCallbackQuery(text: 'Только для админа');

            return;
        }

        // data — все компоненты после "admin:"
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        match ($action) {
            'grant_trial' => $this->handleGrantTrial($bot, $parts),
            'grant_pro' => $this->handleGrantPro($bot, $parts),
            'revoke_subscription' => $this->handleRevokeSubscription($bot, $parts),
            default => $bot->answerCallbackQuery(text: 'Неизвестное действие'),
        };
    }

    /**
     * @param array<int, string> $parts формат: [grant_trial, <tg_id>, confirm|abort]
     */
    private function handleGrantTrial(Nutgram $bot, array $parts): void
    {
        $tgId = $parts[1] ?? '';
        $decision = $parts[2] ?? '';

        if ($decision === 'abort') {
            $this->editAndDrop($bot, '👌 Отменено, ничего не сделал.');
            $bot->answerCallbackQuery();

            return;
        }
        if ($decision !== 'confirm') {
            $bot->answerCallbackQuery(text: 'Неизвестный выбор');

            return;
        }

        $target = $this->adminHandler->findUser($tgId);
        if ($target === null) {
            $this->editAndDrop($bot, 'Пользователь не найден.');
            $bot->answerCallbackQuery();

            return;
        }
        $this->editAndDrop($bot, "Выдаю триал {$tgId}…");
        $bot->answerCallbackQuery();
        $this->adminHandler->performGrantTrial($bot, $target);
    }

    /**
     * @param array<int, string> $parts формат: [grant_pro, <tg_id>, <days>, confirm|abort]
     */
    private function handleGrantPro(Nutgram $bot, array $parts): void
    {
        $tgId = $parts[1] ?? '';
        $daysRaw = $parts[2] ?? '';
        $decision = $parts[3] ?? '';

        if ($decision === 'abort') {
            $this->editAndDrop($bot, '👌 Отменено, ничего не сделал.');
            $bot->answerCallbackQuery();

            return;
        }
        if ($decision !== 'confirm') {
            $bot->answerCallbackQuery(text: 'Неизвестный выбор');

            return;
        }

        if (!ctype_digit($daysRaw) || (int) $daysRaw < 1) {
            $this->editAndDrop($bot, 'Битые аргументы.');
            $bot->answerCallbackQuery();

            return;
        }
        $target = $this->adminHandler->findUser($tgId);
        if ($target === null) {
            $this->editAndDrop($bot, 'Пользователь не найден.');
            $bot->answerCallbackQuery();

            return;
        }
        $this->editAndDrop($bot, "Выдаю Pro {$tgId} на {$daysRaw} дней…");
        $bot->answerCallbackQuery();
        $this->adminHandler->performGrantPro($bot, $target, (int) $daysRaw);
    }

    /**
     * @param array<int, string> $parts формат: [revoke_subscription, <tg_id>, confirm|abort]
     */
    private function handleRevokeSubscription(Nutgram $bot, array $parts): void
    {
        $tgId = $parts[1] ?? '';
        $decision = $parts[2] ?? '';

        if ($decision === 'abort') {
            $this->editAndDrop($bot, '👌 Отменено, ничего не сделал.');
            $bot->answerCallbackQuery();

            return;
        }
        if ($decision !== 'confirm') {
            $bot->answerCallbackQuery(text: 'Неизвестный выбор');

            return;
        }

        $target = $this->adminHandler->findUser($tgId);
        if ($target === null) {
            $this->editAndDrop($bot, 'Пользователь не найден.');
            $bot->answerCallbackQuery();

            return;
        }
        $this->editAndDrop($bot, "Отключаю подписку {$tgId}…");
        $bot->answerCallbackQuery();
        $this->adminHandler->performRevokeSubscription($bot, $target);
    }

    private function editAndDrop(Nutgram $bot, string $text): void
    {
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($chatId === null || $messageId === null) {
            return;
        }
        $bot->editMessageText(
            text: $text,
            chat_id: $chatId,
            message_id: $messageId,
            reply_markup: null,
        );
    }
}
