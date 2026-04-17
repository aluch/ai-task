<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Service\FreeSuggestionStore;
use App\Service\TelegramUserResolver;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Uid\Uuid;

class FreeCallbackHandler
{
    private const MAX_REROLLS = 3;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly FreeHandler $freeHandler,
        private readonly FreeSuggestionStore $store,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $bot->answerCallbackQuery();

        $parts = explode(':', $data);
        if (count($parts) < 3 || $parts[0] !== 'free') {
            return;
        }

        $shortKey = $parts[1];
        $action = $parts[2];

        $state = $this->store->load($shortKey);
        if ($state === null) {
            $bot->editMessageText(
                text: '⏰ Предложение устарело, используй /free заново.',
                reply_markup: null,
            );

            return;
        }

        $user = $this->userResolver->resolve($bot);
        if ($user->getId()->toRfc4122() !== ($state['user_id'] ?? null)) {
            return; // защита от чужих callback'ов
        }

        match ($action) {
            'take' => $this->handleTake($bot, $state, $shortKey),
            'reroll' => $this->handleReroll($bot, $user, $state, $shortKey),
            'dismiss' => $this->handleDismiss($bot, $shortKey),
            default => null,
        };
    }

    /**
     * @param array{task_ids: string[]} $state
     */
    private function handleTake(Nutgram $bot, array $state, string $shortKey): void
    {
        $em = $this->doctrine->getManager();
        $taskIds = $state['task_ids'] ?? [];

        $count = 0;
        foreach ($taskIds as $idStr) {
            if (!Uuid::isValid($idStr)) {
                continue;
            }
            $task = $this->tasks->find(Uuid::fromString($idStr));
            if ($task === null) {
                continue;
            }
            if ($task->getStatus() === TaskStatus::PENDING) {
                $task->setStatus(TaskStatus::IN_PROGRESS);
                $count++;
            }
        }
        $em->flush();

        $this->store->delete($shortKey);

        $bot->editMessageText(
            text: "🚀 Удачи! Когда закончишь — /done\n\n(взято задач: {$count})",
            reply_markup: null,
        );
    }

    /**
     * @param array{task_ids: string[], minutes: int, context: ?string, excluded_ids: string[], reroll_count: int} $state
     */
    private function handleReroll(
        Nutgram $bot,
        \App\Entity\User $user,
        array $state,
        string $shortKey,
    ): void {
        $rerollCount = ($state['reroll_count'] ?? 0) + 1;
        if ($rerollCount > self::MAX_REROLLS) {
            $bot->editMessageText(
                text: '🤷 Больше ничего не подобрать. Начни с того, что уже предложено, или посмотри /list.',
                reply_markup: null,
            );
            $this->store->delete($shortKey);

            return;
        }

        // Накапливаем exclude'ы: предыдущие + все из предыдущего состояния
        $previousExcludes = $state['excluded_ids'] ?? [];
        $newExcludes = array_values(array_unique(array_merge($previousExcludes, $state['task_ids'] ?? [])));

        $minutes = $state['minutes'] ?? 60;
        $context = $state['context'] ?? null;

        // Загружаем задачи заново через FreeHandler-логику (но напрямую — чтобы не делать лишний sendMessage)
        $all = $this->tasks->findUnblockedForUser($user);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $availableTasks = array_values(array_filter($all, static function (Task $t) use ($now): bool {
            $status = $t->getStatus();
            if ($status !== TaskStatus::PENDING && $status !== TaskStatus::IN_PROGRESS) {
                return false;
            }
            $snoozed = $t->getSnoozedUntil();
            if ($snoozed !== null && $snoozed > $now) {
                return false;
            }

            return true;
        }));

        $dto = $this->freeHandler->suggestWithRetry($user, $minutes, $context, $availableTasks, $newExcludes);

        if ($dto === null) {
            $bot->editMessageText(
                text: '⚠️ Не получилось подобрать задачу, попробуй ещё раз через минуту.',
                reply_markup: null,
            );
            $this->store->delete($shortKey);

            return;
        }

        // Старый ключ удаляем — renderSuggestion сохранит новый
        $this->store->delete($shortKey);

        $chatId = $bot->chatId();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        $this->freeHandler->renderSuggestion(
            bot: $bot,
            chatId: $chatId,
            messageId: $messageId,
            user: $user,
            dto: $dto,
            minutes: $minutes,
            context: $context,
            accumulatedExcludes: $newExcludes,
            rerollCount: $rerollCount,
        );
    }

    private function handleDismiss(Nutgram $bot, string $shortKey): void
    {
        $this->store->delete($shortKey);
        $bot->editMessageText(
            text: 'Ок, отдыхай! 🌴',
            reply_markup: null,
        );
    }
}
