<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Enum\TaskStatus;
use App\Service\PaginationStore;
use App\Service\TelegramUserResolver;
use SergiX44\Nutgram\Nutgram;

/**
 * Обрабатывает callback'и пагинации /list:
 *   list:p:<sessionKey>:<page>   — переход на страницу
 *   list:close:<sessionKey>       — убрать сообщение (заменить на подтверждение)
 *   list:noop:<sessionKey>        — клик на индикатор «Стр. N/M»
 */
class ListCallbackHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly PaginationStore $store,
        private readonly ListHandler $listHandler,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $bot->answerCallbackQuery();

        $parts = explode(':', $data);
        if (($parts[0] ?? '') !== 'list') {
            return;
        }
        $action = $parts[1] ?? '';
        $sessionKey = $parts[2] ?? '';

        if ($action === 'noop') {
            return;
        }

        $session = $this->store->get($sessionKey);
        if ($session === null) {
            $bot->editMessageText(
                text: '⏰ Сессия устарела, используй /list заново.',
                reply_markup: null,
            );

            return;
        }

        $user = $this->userResolver->resolve($bot);
        if ($user->getId()->toRfc4122() !== ($session['user_id'] ?? null)) {
            return;
        }

        if ($action === 'close') {
            $bot->editMessageText(text: 'Список закрыт.', reply_markup: null);
            $this->store->delete($sessionKey);

            return;
        }

        if ($action !== 'p') {
            return;
        }

        $page = (int) ($parts[3] ?? 1);
        $statuses = $this->hydrateStatuses($session['filter']['statuses'] ?? null);
        $filterLabel = $session['filter']['filter_label'] ?? null;
        $total = (int) ($session['total'] ?? 0);

        $messageId = $bot->callbackQuery()?->message?->message_id;

        $this->listHandler->renderPage(
            bot: $bot,
            user: $user,
            sessionKey: $sessionKey,
            statuses: $statuses,
            filterLabel: $filterLabel,
            page: $page,
            total: $total,
            editMessageId: $messageId,
        );
    }

    /**
     * @param array<string>|null $rawStatuses
     * @return TaskStatus[]|null
     */
    private function hydrateStatuses(?array $rawStatuses): ?array
    {
        if ($rawStatuses === null) {
            return null;
        }
        if ($rawStatuses === []) {
            return [];
        }
        $hydrated = [];
        foreach ($rawStatuses as $val) {
            $status = TaskStatus::tryFrom((string) $val);
            if ($status !== null) {
                $hydrated[] = $status;
            }
        }

        return $hydrated;
    }
}
