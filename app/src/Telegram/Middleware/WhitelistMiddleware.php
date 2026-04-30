<?php

declare(strict_types=1);

namespace App\Telegram\Middleware;

use App\Service\AccessGate;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Whitelist gate. Пускает только allowed-пользователей и админа.
 * Незваным показывает «доступ ограничен» с кнопкой «Запросить доступ»
 * (callback access:request → создаёт запрос + уведомляет админа).
 *
 * Раньше Whitelist жил в env-переменной TELEGRAM_ALLOWED_USER_IDS, что
 * требовало перевыкатки для каждого нового друга. Теперь — users.is_allowed
 * в БД, управляется через /admin invite/approve без редеплоя.
 *
 * Проброс callback'ов с префиксом access:* делает исключение: без него
 * пользователь не смог бы кликнуть по кнопке «Запросить доступ».
 */
class WhitelistMiddleware
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly AccessGate $gate,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, $next): void
    {
        // Resolve User (find-or-create по telegram_id). Для callback_query —
        // тоже работает, у callback есть from.
        try {
            $user = $this->userResolver->resolve($bot);
        } catch (\Throwable $e) {
            $this->logger->error('WhitelistMiddleware: user resolve failed', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Allowed — пускаем дальше (admin тоже сюда попадает, AccessGate
        // его auto-flags при первом обращении).
        if ($this->gate->isAllowed($user)) {
            $next($bot);

            return;
        }

        // Не allowed. Исключение: callback access:* — пользователь жмёт
        // нашу же кнопку «Запросить доступ», его нужно пропустить
        // на handler этого callback'а.
        $cbData = $bot->callbackQuery()?->data ?? '';
        if (str_starts_with($cbData, 'access:')) {
            $next($bot);

            return;
        }

        $this->logger->info('WhitelistMiddleware: blocked unallowed user', [
            'telegram_id' => $user->getTelegramId(),
            'has_request' => $user->getAccessRequestedAt() !== null,
            'rejected' => $user->getRequestRejectedAt() !== null,
        ]);

        $this->showLockScreen($bot, $user);
    }

    private function showLockScreen(Nutgram $bot, \App\Entity\User $user): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $name = $user->getName() ?? 'друг';

        // Уже запросил, ждёт ответа админа.
        if ($user->getAccessRequestedAt() !== null && $user->getRequestRejectedAt() === null) {
            $bot->sendMessage(text: '⏳ Запрос отправлен админу, жди ответа.');

            return;
        }

        // Отклонён недавно — тихо.
        if (!$this->gate->canRequestAccess($user, $now)) {
            $this->logger->info('WhitelistMiddleware: silently dropped (recently rejected)', [
                'telegram_id' => $user->getTelegramId(),
            ]);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(text: '🙏 Запросить доступ', callback_data: 'access:request'),
        );

        $bot->sendMessage(
            text: "👋 Привет, {$name}!\n\n"
                . "К сожалению, доступ к Помни сейчас по приглашениям.",
            reply_markup: $keyboard,
        );
    }
}
