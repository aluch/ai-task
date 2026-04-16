<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\AI\DTO\ParsedTaskDTO;
use App\AI\Exception\ClaudeClientException;
use App\AI\Exception\ClaudeRateLimitException;
use App\AI\Exception\ClaudeTransientException;
use App\AI\TaskParser;
use App\Entity\Task;
use App\Enum\TaskSource;
use App\Repository\TaskContextRepository;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

class FreeTextHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskParser $taskParser,
        private readonly TaskContextRepository $contexts,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = trim($bot->message()?->text ?? '');

        if ($text === '') {
            return;
        }

        $bot->sendMessage(text: '⏳ Разбираю задачу...');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dto = $this->parseWithRetry($text, $user, $now);

        $task = new Task($user, $dto->title);

        if ($dto->description !== null) {
            $task->setDescription($dto->description);
        }
        if ($dto->deadline !== null) {
            $task->setDeadline($dto->deadline);
        }
        if ($dto->estimatedMinutes !== null) {
            $task->setEstimatedMinutes($dto->estimatedMinutes);
        }

        $task->setPriority($dto->priority);
        $task->setSource(TaskSource::AI_PARSED);
        $task->setSourceRef((string) ($bot->message()?->message_id ?? ''));

        if ($dto->contextCodes !== []) {
            $found = $this->contexts->findByCodes($dto->contextCodes);
            foreach ($found as $ctx) {
                $task->addContext($ctx);
            }
        }

        $this->em->persist($task);
        $this->em->flush();

        $bot->sendMessage(text: $this->formatResponse($task, $dto, $user));
    }

    private function parseWithRetry(
        string $text,
        \App\Entity\User $user,
        \DateTimeImmutable $now,
    ): ParsedTaskDTO {
        $maxRetries = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->taskParser->parse($text, $user, $now);
            } catch (ClaudeRateLimitException $e) {
                if ($attempt >= 1) {
                    break;
                }
                $wait = $e->retryAfter ?? 5;
                $this->logger->warning('Claude rate limited, waiting {wait}s', [
                    'wait' => $wait,
                    'attempt' => $attempt,
                ]);
                sleep($wait);
            } catch (ClaudeTransientException $e) {
                if ($attempt >= $maxRetries) {
                    break;
                }
                $wait = $attempt === 0 ? 1 : 3;
                $this->logger->warning('Claude transient error, retrying in {wait}s', [
                    'wait' => $wait,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                sleep($wait);
            } catch (ClaudeClientException $e) {
                $this->logger->error('Claude client error, falling back to simple task', [
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        return new ParsedTaskDTO(title: mb_substr($text, 0, 255));
    }

    private function formatResponse(Task $task, ParsedTaskDTO $dto, \App\Entity\User $user): string
    {
        $userTz = new \DateTimeZone($user->getTimezone());
        $shortId = substr($task->getId()->toRfc4122(), 0, 8);

        $lines = ['✅ Задача создана', ''];
        $lines[] = "📝 {$task->getTitle()}";

        if ($task->getDeadline() !== null) {
            $deadlineLocal = $task->getDeadline()->setTimezone($userTz)->format('d.m H:i');
            $lines[] = "⏰ {$deadlineLocal}";
        }

        if ($dto->priority !== \App\Enum\TaskPriority::MEDIUM) {
            $priEmoji = match ($dto->priority) {
                \App\Enum\TaskPriority::URGENT => '🔴 urgent',
                \App\Enum\TaskPriority::HIGH => '🔥 high',
                \App\Enum\TaskPriority::LOW => '🔽 low',
                default => '',
            };
            if ($priEmoji !== '') {
                $lines[] = $priEmoji;
            }
        }

        if ($dto->contextCodes !== []) {
            $lines[] = '🏷 ' . implode(', ', $dto->contextCodes);
        }

        $lines[] = '';
        $lines[] = "ID: {$shortId}";

        if ($dto->parserNotes !== null) {
            $lines[] = '';
            $lines[] = "💭 {$dto->parserNotes}";
        }

        return implode("\n", $lines);
    }
}
