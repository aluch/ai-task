<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\AI\DTO\TaskSuggestionDTO;
use App\AI\Exception\ClaudeClientException;
use App\AI\Exception\ClaudeRateLimitException;
use App\AI\Exception\ClaudeTransientException;
use App\AI\TaskAdvisor;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Service\FreeSuggestionStore;
use App\Service\RelativeTimeParser;
use App\Service\TelegramUserResolver;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class FreeHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly TaskAdvisor $advisor,
        private readonly RelativeTimeParser $timeParser,
        private readonly FreeSuggestionStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $cmdArgs = trim(substr($text, 5)); // strip "/free"
        if ($cmdArgs === '') {
            $bot->sendMessage(
                text: <<<'MSG'
                Используй: /free <время> [контекст]

                Примеры:
                  /free 2h
                  /free 30m дома
                  /free 1h на улице
                  /free 90m на даче
                MSG,
            );

            return;
        }

        // Первое слово — время, остальное — контекст
        $parts = preg_split('/\s+/', $cmdArgs, 2);
        $durationRaw = $parts[0];
        $context = isset($parts[1]) ? trim($parts[1]) : null;

        try {
            $minutes = $this->timeParser->parseToMinutes($durationRaw);
        } catch (\InvalidArgumentException) {
            $bot->sendMessage(text: "Не понял «{$durationRaw}». Примеры: 30m, 1h, 2h, 1.5h, 90m, 2ч.");

            return;
        }

        $tasks = $this->loadAvailableTasks($user);
        if ($tasks === []) {
            $bot->sendMessage(text: 'У тебя нет открытых задач. Напиши мне что-нибудь — создам задачу.');

            return;
        }

        $thinkingMsg = $bot->sendMessage(text: '🤔 Думаю, что бы тебе предложить...');
        $chatId = $bot->chatId();
        $messageId = $thinkingMsg?->message_id;

        $dto = $this->suggestWithRetry($user, $minutes, $context, $tasks, []);

        if ($dto === null) {
            $this->editOrSend(
                $bot,
                $chatId,
                $messageId,
                '⚠️ Не получилось подобрать задачу, попробуй ещё раз через минуту.',
            );

            return;
        }

        $this->renderSuggestion($bot, $chatId, $messageId, $user, $dto, $minutes, $context, []);
    }

    /**
     * @return Task[]
     */
    private function loadAvailableTasks(User $user): array
    {
        $all = $this->tasks->findUnblockedForUser($user);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return array_values(array_filter($all, static function (Task $t) use ($now): bool {
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
    }

    /**
     * @param Task[] $tasks
     * @param string[] $excludeIds
     */
    public function suggestWithRetry(
        User $user,
        int $minutes,
        ?string $context,
        array $tasks,
        array $excludeIds,
    ): ?TaskSuggestionDTO {
        $maxRetries = 2;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $dto = $this->advisor->suggest($user, $minutes, $context, $tasks, $now, $excludeIds);

                $this->logger->info('TaskAdvisor reasoning', [
                    'user_id' => $user->getId()->toRfc4122(),
                    'available_minutes' => $minutes,
                    'context' => $context,
                    'suggestions_count' => count($dto->suggestions),
                    'internal_reasoning' => $dto->internalReasoning,
                ]);

                return $dto;
            } catch (ClaudeRateLimitException $e) {
                if ($attempt >= 1) {
                    break;
                }
                $wait = $e->retryAfter ?? 5;
                $this->logger->warning('TaskAdvisor rate limited, waiting {wait}s', ['wait' => $wait]);
                sleep($wait);
            } catch (ClaudeTransientException $e) {
                if ($attempt >= $maxRetries) {
                    break;
                }
                $wait = $attempt === 0 ? 1 : 3;
                $this->logger->warning('TaskAdvisor transient error, retrying in {wait}s', [
                    'wait' => $wait,
                    'error' => $e->getMessage(),
                ]);
                sleep($wait);
            } catch (ClaudeClientException $e) {
                $this->logger->error('TaskAdvisor client error', ['error' => $e->getMessage()]);

                return null;
            }
        }

        return null;
    }

    /**
     * @param string[] $accumulatedExcludes
     */
    public function renderSuggestion(
        Nutgram $bot,
        int $chatId,
        ?int $messageId,
        User $user,
        TaskSuggestionDTO $dto,
        int $minutes,
        ?string $context,
        array $accumulatedExcludes,
        int $rerollCount = 0,
    ): void {
        if ($dto->suggestions === []) {
            $reason = $dto->noMatchReason ?? 'Подходящих задач не нашлось.';
            $this->editOrSend(
                $bot,
                $chatId,
                $messageId,
                "🤷 Ничего подходящего не нашлось\n\n💭 {$reason}",
            );

            return;
        }

        $taskIds = array_map(fn ($s) => $s->taskId, $dto->suggestions);

        // Карта task_id → Task для отрисовки
        $taskMap = [];
        foreach ($this->tasks->findBy(['user' => $user]) as $task) {
            $taskMap[$task->getId()->toRfc4122()] = $task;
        }

        $userTz = new \DateTimeZone($user->getTimezone());
        $text = $this->formatSuggestion($dto, $taskMap, $userTz, $minutes, $context);

        $shortKey = $this->store->save(
            userId: $user->getId()->toRfc4122(),
            taskIds: $taskIds,
            minutes: $minutes,
            context: $context,
            excludedIds: $accumulatedExcludes,
            rerollCount: $rerollCount,
        );

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(text: '✅ Беру!', callback_data: "free:{$shortKey}:take"))
            ->addRow(
                InlineKeyboardButton::make(text: '🔄 Другие варианты', callback_data: "free:{$shortKey}:reroll"),
                InlineKeyboardButton::make(text: '❌ Не сейчас', callback_data: "free:{$shortKey}:dismiss"),
            );

        if ($messageId !== null) {
            $bot->editMessageText(
                text: $text,
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: $keyboard,
            );
        } else {
            $bot->sendMessage(text: $text, reply_markup: $keyboard);
        }
    }

    /**
     * @param array<string, Task> $taskMap
     */
    private function formatSuggestion(
        TaskSuggestionDTO $dto,
        array $taskMap,
        \DateTimeZone $userTz,
        int $minutes,
        ?string $context,
    ): string {
        $header = $context !== null && $context !== ''
            ? "📋 План на {$this->formatMinutes($minutes)} ({$context}):"
            : "📋 План на {$this->formatMinutes($minutes)}:";

        $lines = [$header, ''];
        $digitEmoji = ['0️⃣', '1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣'];

        foreach ($dto->suggestions as $i => $sug) {
            $task = $taskMap[$sug->taskId] ?? null;
            if ($task === null) {
                continue;
            }
            $num = $i + 1;
            $numEmoji = $digitEmoji[$num] ?? "{$num}.";

            $lines[] = "{$numEmoji} {$task->getTitle()}";

            $metaParts = [];
            $est = $sug->estimatedMinutes ?? $task->getEstimatedMinutes();
            if ($est !== null) {
                $metaParts[] = '⏱ ~' . $this->formatMinutes($est);
            }
            if ($task->getDeadline() !== null) {
                $metaParts[] = '⏰ ' . $this->formatDeadline($task->getDeadline(), $userTz);
            }
            $priority = $task->getPriority();
            if ($priority === TaskPriority::URGENT) {
                $metaParts[] = '🔴 urgent';
            } elseif ($priority === TaskPriority::HIGH) {
                $metaParts[] = '🔥 high';
            }

            if ($metaParts !== []) {
                $lines[] = '   ' . implode(' | ', $metaParts);
            }

            if ($sug->tip !== null) {
                $lines[] = "   💡 {$sug->tip}";
            }

            $lines[] = '';
        }

        $lines[] = "⏱ Итого: ~{$this->formatMinutes($dto->totalEstimatedMinutes)} из {$this->formatMinutes($minutes)} доступных";

        if ($dto->userSummary !== null) {
            $lines[] = '';
            $lines[] = "💭 {$dto->userSummary}";
        }

        return implode("\n", $lines);
    }

    private function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} мин";
        }
        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        return $rem === 0 ? "{$hours} ч" : "{$hours} ч {$rem} мин";
    }

    private function formatDeadline(\DateTimeImmutable $deadline, \DateTimeZone $userTz): string
    {
        $now = new \DateTimeImmutable('now', $userTz);
        $local = $deadline->setTimezone($userTz);

        $today = $now->format('Y-m-d');
        $deadlineDate = $local->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        if ($deadlineDate === $today) {
            return 'сегодня ' . $local->format('H:i');
        }

        if ($deadlineDate === $tomorrow) {
            return 'завтра ' . $local->format('H:i');
        }

        return $local->format('d.m H:i');
    }

    private function editOrSend(Nutgram $bot, int $chatId, ?int $messageId, string $text): void
    {
        if ($messageId !== null) {
            $bot->editMessageText(text: $text, chat_id: $chatId, message_id: $messageId, reply_markup: null);
        } else {
            $bot->sendMessage(text: $text);
        }
    }
}
