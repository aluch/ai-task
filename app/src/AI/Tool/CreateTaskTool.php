<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\TaskParser;
use App\Entity\Task;
use App\Entity\TaskContext;
use App\Entity\User;
use App\Enum\TaskSource;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class CreateTaskTool implements AssistantTool
{
    public function __construct(
        private readonly TaskParser $taskParser,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'create_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Создать новую задачу для пользователя. Используй когда пользователь описывает что-то, что ему нужно сделать.
        Извлекай структуру (заголовок, дедлайн, приоритет, контексты) из исходного текста — TaskParser сделает это автоматически.
        Передавай raw_text = полный текст пользователя с описанием задачи.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'raw_text' => [
                    'type' => 'string',
                    'description' => 'Исходный текст пользователя с описанием задачи (полностью, без изменений)',
                ],
            ],
            'required' => ['raw_text'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $rawText = trim((string) ($input['raw_text'] ?? ''));
        if ($rawText === '') {
            return ToolResult::error('raw_text обязателен');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dto = $this->taskParser->parse($rawText, $user, $now);

        $em = $this->doctrine->getManager();
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

        if ($dto->contextCodes !== []) {
            $contextRepo = $em->getRepository(TaskContext::class);
            $found = $contextRepo->createQueryBuilder('c')
                ->andWhere('c.code IN (:codes)')
                ->setParameter('codes', $dto->contextCodes)
                ->getQuery()
                ->getResult();
            foreach ($found as $ctx) {
                $task->addContext($ctx);
            }
        }

        $em->persist($task);
        $em->flush();

        $this->logger->info('Assistant created task', [
            'task_id' => $task->getId()->toRfc4122(),
            'title' => $task->getTitle(),
        ]);

        $parts = ["Создана задача: «{$task->getTitle()}»"];
        if ($task->getDeadline() !== null) {
            $userTz = new \DateTimeZone($user->getTimezone());
            $parts[] = 'дедлайн: ' . $task->getDeadline()->setTimezone($userTz)->format('Y-m-d H:i');
        }
        $parts[] = 'приоритет: ' . $task->getPriority()->value;
        if ($dto->contextCodes !== []) {
            $parts[] = 'контексты: ' . implode(', ', $dto->contextCodes);
        }

        return ToolResult::ok(
            implode(', ', $parts),
            ['task_id' => $task->getId()->toRfc4122()],
        );
    }
}
