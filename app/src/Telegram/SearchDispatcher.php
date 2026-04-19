<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\User;
use App\Service\PaginationStore;
use App\Telegram\Handler\BlockHandler;
use App\Telegram\Handler\DepsHandler;
use App\Telegram\Handler\DoneHandler;
use App\Telegram\Handler\SnoozeHandler;
use App\Telegram\Handler\UnblockHandler;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Когда пользователь нажал «🔍 Поиск» в одном из меню (done/snooze/deps/block/unblock)
 * и пишет поисковый запрос, dispatch выбирает нужный handler по action'у сохранённой
 * сессии и рендерит первую страницу с filter.search=<text>.
 *
 * Используется из AssistantHandler — он первым делом смотрит waiting_search
 * в PaginationStore и, если найден, передаёт текст сюда вместо обычной обработки.
 */
class SearchDispatcher
{
    public function __construct(
        private readonly PaginationStore $paginationStore,
        private readonly DoneHandler $doneHandler,
        private readonly SnoozeHandler $snoozeHandler,
        private readonly DepsHandler $depsHandler,
        private readonly BlockHandler $blockHandler,
        private readonly UnblockHandler $unblockHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Возвращает true если обработал поисковый запрос (handler запущен).
     * False — если сессии нет или она неизвестного action'а (AssistantHandler
     * продолжит обычную обработку).
     */
    public function dispatchIfWaiting(Nutgram $bot, User $user, string $query): bool
    {
        $telegramId = (string) $bot->userId();
        $sessionKey = $this->paginationStore->getWaitingSearch($telegramId);
        if ($sessionKey === null) {
            return false;
        }

        $this->paginationStore->clearWaitingSearch($telegramId);

        $session = $this->paginationStore->get($sessionKey);
        if ($session === null) {
            $bot->sendMessage(text: '⏰ Сессия поиска устарела, попробуй заново через команду.');

            return true;
        }

        $action = (string) ($session['action'] ?? '');
        $searchRoot = $this->toSearchRoot($query);

        $this->logger->info('Search dispatch', [
            'action' => $action,
            'query' => $query,
            'search_root' => $searchRoot,
        ]);

        // Старая сессия больше не нужна — handler создаст новую с фильтром
        $this->paginationStore->delete($sessionKey);

        match ($action) {
            DoneHandler::ACTION => $this->doneHandler->renderFirstPage($bot, $user, $searchRoot),
            SnoozeHandler::ACTION => $this->snoozeHandler->renderFirstPage($bot, $user, $searchRoot),
            DepsHandler::ACTION => $this->depsHandler->renderFirstPage($bot, $user, $searchRoot),
            BlockHandler::ACTION => $this->blockHandler->renderFirstPage($bot, $user, $searchRoot),
            UnblockHandler::ACTION => $this->unblockHandler->renderFirstPage($bot, $user, $searchRoot),
            default => $bot->sendMessage(text: 'Не знаю что делать с этим поиском, начни заново.'),
        };

        return true;
    }

    /**
     * Тот же стемминг что в MarkTaskDoneTool/SnoozeTaskTool — режем последние
     * 2 символа если слово >3 символов. Покрывает падежи.
     */
    private function toSearchRoot(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        if (mb_strlen($normalized) > 3) {
            $normalized = mb_substr($normalized, 0, -2);
        }

        return $normalized;
    }
}
