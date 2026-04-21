<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class UnblockTaskTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskLookup $lookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'unblock_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Убрать зависимость между двумя задачами: первая больше не блокируется второй.
        Используй когда пользователь говорит «отменяй связку», «эта задача больше
        не зависит от той», «сделаю независимо».
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'blocked_task_id_or_query' => [
                    'type' => 'string',
                    'description' => 'Задача, с которой снимаем блокировку.',
                ],
                'blocker_task_id_or_query' => [
                    'type' => 'string',
                    'description' => 'Задача-блокер, которую убираем из зависимостей.',
                ],
            ],
            'required' => ['blocked_task_id_or_query', 'blocker_task_id_or_query'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $blocked = $this->resolveAny($user, trim((string) ($input['blocked_task_id_or_query'] ?? '')));
        if ($blocked instanceof ToolResult) {
            return $blocked;
        }
        $blocker = $this->resolveAny($user, trim((string) ($input['blocker_task_id_or_query'] ?? '')));
        if ($blocker instanceof ToolResult) {
            return $blocker;
        }

        $wasBlocked = false;
        foreach ($blocked->getBlockedBy() as $existing) {
            if ($existing->getId()->equals($blocker->getId())) {
                $wasBlocked = true;
                break;
            }
        }
        if (!$wasBlocked) {
            return ToolResult::error(
                "«{$blocked->getTitle()}» и так не заблокирована задачей «{$blocker->getTitle()}».",
            );
        }

        $blocked->removeBlocker($blocker);
        $this->doctrine->getManager()->flush();

        $this->logger->info('Assistant unblocked task', [
            'blocked_id' => $blocked->getId()->toRfc4122(),
            'blocker_id' => $blocker->getId()->toRfc4122(),
        ]);

        return ToolResult::ok(
            "Снял зависимость: «{$blocked->getTitle()}» больше не ждёт «{$blocker->getTitle()}».",
            [
                'blocked_id' => $blocked->getId()->toRfc4122(),
                'blocker_id' => $blocker->getId()->toRfc4122(),
            ],
        );
    }

    private function resolveAny(User $user, string $ref): \App\Entity\Task|ToolResult
    {
        if (\Symfony\Component\Uid\Uuid::isValid($ref)) {
            return $this->lookup->resolve($user, $ref, '');
        }

        return $this->lookup->resolve($user, '', $ref);
    }
}
