<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\User;
use App\Exception\CyclicDependencyException;
use App\Service\DependencyValidator;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class BlockTaskTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskLookup $lookup,
        private readonly DependencyValidator $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'block_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Указать, что одна задача заблокирована другой (должна быть сделана после).
        Например: «чтобы купить билеты, нужно сначала снять наличку» → blocked=билеты,
        blocker=наличка. После выполнения задачи-блокера blocked автоматически
        разблокируется и снова попадёт в списки «что делать».

        Проверяет циклы: если связь создаст кольцо, вернёт ошибку.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'blocked_task_id_or_query' => [
                    'type' => 'string',
                    'description' => 'UUID или часть названия задачи, которая БЛОКИРУЕТСЯ.',
                ],
                'blocker_task_id_or_query' => [
                    'type' => 'string',
                    'description' => 'UUID или часть названия задачи, которая БЛОКИРУЕТ.',
                ],
            ],
            'required' => ['blocked_task_id_or_query', 'blocker_task_id_or_query'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $blockedRef = trim((string) ($input['blocked_task_id_or_query'] ?? ''));
        $blockerRef = trim((string) ($input['blocker_task_id_or_query'] ?? ''));

        $blocked = $this->resolveAny($user, $blockedRef);
        if ($blocked instanceof ToolResult) {
            return $blocked;
        }
        $blocker = $this->resolveAny($user, $blockerRef);
        if ($blocker instanceof ToolResult) {
            return $blocker;
        }

        try {
            $this->validator->validateNoCycle($blocked, $blocker);
        } catch (CyclicDependencyException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\LogicException $e) {
            return ToolResult::error($e->getMessage());
        }

        $blocked->addBlocker($blocker);
        $this->doctrine->getManager()->flush();

        $this->logger->info('Assistant blocked task', [
            'blocked_id' => $blocked->getId()->toRfc4122(),
            'blocker_id' => $blocker->getId()->toRfc4122(),
        ]);

        return ToolResult::ok(
            "Связал: «{$blocked->getTitle()}» ждёт «{$blocker->getTitle()}».",
            [
                'blocked_id' => $blocked->getId()->toRfc4122(),
                'blocker_id' => $blocker->getId()->toRfc4122(),
            ],
        );
    }

    private function resolveAny(User $user, string $ref): \App\Entity\Task|ToolResult
    {
        // Пробуем как UUID, иначе — как query
        if (\Symfony\Component\Uid\Uuid::isValid($ref)) {
            return $this->lookup->resolve($user, $ref, '');
        }

        return $this->lookup->resolve($user, '', $ref);
    }
}
