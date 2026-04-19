<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Service\DependencyStateStore;
use App\Service\PaginationStore;
use App\Service\TelegramUserResolver;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\Uid\Uuid;

/**
 * Callback-протокол для /block и /unblock flow:
 *
 *   dep:s1:<blocked_uuid>             — step 1 block: выбрана блокируемая
 *   dep:s2:<state_key>:<blocker_uuid> — step 2 block: выбран блокер
 *   dep:u1:<blocked_uuid>             — step 1 unblock: выбрана задача
 *   dep:u2:<state_key>:<blocker_uuid> — step 2 unblock: выбран блокер к удалению
 *
 * state_key хранит blocked_uuid в Redis (TTL 10 мин). Это нужно потому что
 * 2 полных UUID в callback_data не помещаются (64-байт лимит Telegram).
 */
class DependencyCallbackHandler
{
    private const MAX_BUTTONS = 8;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly BlockHandler $blockHandler,
        private readonly UnblockHandler $unblockHandler,
        private readonly DependencyStateStore $stateStore,
        private readonly PaginationStore $paginationStore,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $bot->answerCallbackQuery();

        $user = $this->userResolver->resolve($bot);

        $parts = explode(':', $data);
        if (count($parts) < 3 || $parts[0] !== 'dep') {
            return;
        }

        $step = $parts[1];

        // Menu controls (pagination для списка задач на step1):
        //   dep:s1:m:p:<key>:<page>   → пагинация block-step1
        //   dep:u1:m:...              → unblock-step1
        if (in_array($step, ['s1', 'u1'], true) && ($parts[2] ?? '') === 'm') {
            $this->handleMenu($bot, $user, $step, $parts);

            return;
        }

        match ($step) {
            's1' => $this->handleBlockStep1($bot, $user, $parts[2]),
            's2' => $this->handleBlockStep2($bot, $user, $parts[2], $parts[3] ?? ''),
            'u1' => $this->handleUnblockStep1($bot, $user, $parts[2]),
            'u2' => $this->handleUnblockStep2($bot, $user, $parts[2], $parts[3] ?? ''),
            default => null,
        };
    }

    /**
     * @param string[] $parts
     */
    private function handleMenu(Nutgram $bot, User $user, string $flow, array $parts): void
    {
        $menuAction = $parts[3] ?? '';
        $sessionKey = $parts[4] ?? '';
        $messageId = $bot->callbackQuery()?->message?->message_id;

        if ($menuAction === 'noop') {
            return;
        }

        $session = $this->paginationStore->get($sessionKey);
        if ($session === null) {
            $bot->editMessageText(
                text: '⏰ Сессия устарела, используй команду заново.',
                reply_markup: null,
            );

            return;
        }

        if ($user->getId()->toRfc4122() !== ($session['user_id'] ?? null)) {
            return;
        }

        if ($menuAction === 'close') {
            $bot->editMessageText(text: 'Список закрыт.', reply_markup: null);
            $this->paginationStore->delete($sessionKey);

            return;
        }

        if ($menuAction === 'search') {
            $this->paginationStore->setWaitingSearch((string) $bot->userId(), $sessionKey);
            $bot->editMessageText(
                text: '🔍 Напиши часть названия задачи, которую ищешь:',
                reply_markup: null,
            );

            return;
        }

        if ($menuAction !== 'p') {
            return;
        }

        $page = (int) ($parts[5] ?? 1);
        $search = (string) ($session['filter']['search'] ?? '');
        $total = (int) ($session['total'] ?? 0);

        if ($flow === 's1') {
            $this->blockHandler->renderPage($bot, $user, $sessionKey, $search, $page, $total, $messageId);
        } else {
            $this->unblockHandler->renderPage($bot, $user, $sessionKey, $search, $page, $total, $messageId);
        }
    }

    private function handleBlockStep1(Nutgram $bot, User $user, string $blockedUuid): void
    {
        $blocked = $this->findByUuid($blockedUuid, $user);
        if ($blocked === null) {
            $bot->editMessageText(text: 'Задача не найдена.', reply_markup: null);

            return;
        }

        $tasks = $this->tasks->findForUser($user, limit: self::MAX_BUTTONS + 1);

        $stateKey = $this->stateStore->store($blockedUuid);

        $keyboard = InlineKeyboardMarkup::make();
        $shown = 0;
        foreach ($tasks as $task) {
            if ($shown >= self::MAX_BUTTONS) {
                break;
            }
            if ($task->getId()->equals($blocked->getId())) {
                continue;
            }
            $uuid = $task->getId()->toRfc4122();
            $label = $this->truncate($task->getTitle(), 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $label,
                    callback_data: "dep:s2:{$stateKey}:{$uuid}",
                ),
            );
            $shown++;
        }

        $bot->editMessageText(
            text: "⛔ {$blocked->getTitle()}\n⬅️ заблокирована чем?",
            reply_markup: $keyboard,
        );
    }

    private function handleBlockStep2(Nutgram $bot, User $user, string $stateKey, string $blockerUuid): void
    {
        $blockedUuid = $this->stateStore->load($stateKey);
        if ($blockedUuid === null) {
            $bot->editMessageText(
                text: '⏰ Сессия истекла, начни заново через /block.',
                reply_markup: null,
            );

            return;
        }

        $this->blockHandler->createLink($bot, $user, $blockedUuid, $blockerUuid, editMessage: true);
        $this->stateStore->delete($stateKey);
    }

    private function handleUnblockStep1(Nutgram $bot, User $user, string $blockedUuid): void
    {
        $blocked = $this->findByUuid($blockedUuid, $user);
        if ($blocked === null) {
            $bot->editMessageText(text: 'Задача не найдена.', reply_markup: null);

            return;
        }

        $blockers = $blocked->getBlockedBy()->toArray();
        if ($blockers === []) {
            $bot->editMessageText(text: "У задачи «{$blocked->getTitle()}» нет блокеров.", reply_markup: null);

            return;
        }

        $stateKey = $this->stateStore->store($blockedUuid);

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($blockers as $blocker) {
            $uuid = $blocker->getId()->toRfc4122();
            $label = $this->truncate($blocker->getTitle(), 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $label,
                    callback_data: "dep:u2:{$stateKey}:{$uuid}",
                ),
            );
        }

        $bot->editMessageText(
            text: "🔓 Убрать блокер у «{$blocked->getTitle()}»:",
            reply_markup: $keyboard,
        );
    }

    private function handleUnblockStep2(Nutgram $bot, User $user, string $stateKey, string $blockerUuid): void
    {
        $blockedUuid = $this->stateStore->load($stateKey);
        if ($blockedUuid === null) {
            $bot->editMessageText(
                text: '⏰ Сессия истекла, начни заново через /unblock.',
                reply_markup: null,
            );

            return;
        }

        $this->unblockHandler->removeLink($bot, $user, $blockedUuid, $blockerUuid, editMessage: true);
        $this->stateStore->delete($stateKey);
    }

    private function findByUuid(string $uuid, User $user): ?Task
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }
        $task = $this->tasks->find(Uuid::fromString($uuid));
        if ($task === null || $task->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            return null;
        }

        return $task;
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . '…';
    }
}
