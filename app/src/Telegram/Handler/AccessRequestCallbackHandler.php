<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\User;
use App\Service\AccessGate;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\Uid\Uuid;

/**
 * Обрабатывает три callback'а access:* :
 *   access:request                — пользователь просит доступ (ставит
 *                                    accessRequestedAt + уведомляет админа)
 *   access:approve:<user_uuid>    — админ одобрил запрос (isAllowed=true,
 *                                    нотификация юзеру)
 *   access:reject:<user_uuid>     — админ отклонил (requestRejectedAt=now,
 *                                    короткая отписка юзеру)
 */
class AccessRequestCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly AccessGate $gate,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, string $data): void
    {
        // data = "request" или "approve:<uuid>" или "reject:<uuid>"
        $parts = explode(':', $data, 2);
        $action = $parts[0];

        match ($action) {
            'request' => $this->handleRequest($bot),
            'approve' => $this->handleApproval($bot, (string) ($parts[1] ?? ''), true),
            'reject' => $this->handleApproval($bot, (string) ($parts[1] ?? ''), false),
            default => $bot->answerCallbackQuery(text: 'Неизвестное действие'),
        };
    }

    private function handleRequest(Nutgram $bot): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $user = $this->userResolver->resolve($bot);

        if ($this->gate->isAllowed($user)) {
            $bot->answerCallbackQuery(text: 'У тебя уже есть доступ.');

            return;
        }

        if (!$this->gate->canRequestAccess($user, $now)) {
            $bot->answerCallbackQuery(text: 'Запрос недавно отклонён, попробуй позже.');

            return;
        }

        $user->setAccessRequestedAt($now);
        $this->doctrine->getManager()->flush();

        $this->notifyAdmin($bot, $user);

        // Заменяем сообщение с кнопкой на «жди ответа», чтобы юзер не
        // спамил кнопкой.
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($chatId !== null && $messageId !== null) {
            $bot->editMessageText(
                text: '⏳ Запрос отправлен админу, жди ответа.',
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: null,
            );
        }
        $bot->answerCallbackQuery();
    }

    private function handleApproval(Nutgram $bot, string $userUuid, bool $approve): void
    {
        $admin = $this->userResolver->resolve($bot);
        if (!$this->gate->isAdmin($admin)) {
            $bot->answerCallbackQuery(text: 'Только для админа');

            return;
        }
        if (!Uuid::isValid($userUuid)) {
            $bot->answerCallbackQuery(text: 'Битый UUID');

            return;
        }

        $em = $this->doctrine->getManager();
        $target = $em->getRepository(User::class)->find(Uuid::fromString($userUuid));
        if ($target === null) {
            $bot->answerCallbackQuery(text: 'Пользователь не найден');

            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($approve) {
            $target->setAllowed(true);
            $target->setAccessRequestedAt(null);
            $em->flush();
            $this->logger->info('Access approved', [
                'admin' => $admin->getTelegramId(),
                'target' => $target->getTelegramId(),
            ]);
            $this->notifyUser($bot, $target, true);
            $resultText = "✅ Доступ выдан: {$this->describe($target)}";
        } else {
            $target->setRequestRejectedAt($now);
            $target->setAccessRequestedAt(null);
            $em->flush();
            $this->logger->info('Access rejected', [
                'admin' => $admin->getTelegramId(),
                'target' => $target->getTelegramId(),
            ]);
            $this->notifyUser($bot, $target, false);
            $resultText = "❌ Отклонено: {$this->describe($target)}";
        }

        // Убираем кнопки из сообщения админа, заменяем текст на результат.
        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($chatId !== null && $messageId !== null) {
            $bot->editMessageText(
                text: $resultText,
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: null,
            );
        }
        $bot->answerCallbackQuery();
    }

    private function notifyAdmin(Nutgram $bot, User $requester): void
    {
        $adminTgId = $this->gate->adminTelegramId();
        if ($adminTgId === '') {
            $this->logger->warning('Access request: ADMIN_TELEGRAM_ID не задан, уведомление не отправлено');

            return;
        }

        $username = $bot->message()?->from?->username
            ?? $bot->callbackQuery()?->from?->username;
        $usernameLine = $username !== null ? "💬 @{$username}\n" : '';

        $userUuid = $requester->getId()->toRfc4122();
        $name = $requester->getName() ?? '(без имени)';
        $tgId = $requester->getTelegramId() ?? '?';

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(text: '✅ Разрешить', callback_data: "access:approve:{$userUuid}"),
            InlineKeyboardButton::make(text: '❌ Отклонить', callback_data: "access:reject:{$userUuid}"),
        );

        $bot->sendMessage(
            text: "🔔 Запрос доступа\n\n👤 {$name}\n🆔 {$tgId}\n{$usernameLine}",
            chat_id: (int) $adminTgId,
            reply_markup: $keyboard,
        );
    }

    private function notifyUser(Nutgram $bot, User $target, bool $approved): void
    {
        $tgId = $target->getTelegramId();
        if ($tgId === null) {
            return;
        }
        $text = $approved
            ? '✅ Доступ открыт! Напиши мне любое сообщение или /start.'
            : 'К сожалению, доступ не одобрен. Извини.';
        try {
            $bot->sendMessage(text: $text, chat_id: (int) $tgId);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not notify user about access decision', [
                'telegram_id' => $tgId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function describe(User $u): string
    {
        return ($u->getName() ?? '?') . ' (tg:' . ($u->getTelegramId() ?? '?') . ')';
    }
}
