<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\TaskAdvisor;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class SuggestTasksTool implements AssistantTool
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskAdvisor $advisor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'suggest_tasks';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Подобрать задачи, которые пользователь может сделать прямо сейчас, с учётом
        доступного времени и контекста (где он сейчас, что есть под рукой).
        Используй когда пользователь спрашивает «что мне сделать?», «я свободен на
        час, что взять?», «чем заняться?».

        Возвращает текст с 2-5 предложенными задачами и кратким обоснованием выбора
        (приоритеты, дедлайны, контексты). Это только СОВЕТ — без inline-кнопок.
        Если пользователь хочет взять задачу с action-кнопками, подскажи команду /free.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'available_minutes' => [
                    'type' => 'integer',
                    'description' => 'Сколько минут пользователь готов потратить.',
                ],
                'context_description' => [
                    'type' => 'string',
                    'description' => 'Где он и что есть под рукой: «дома с ноутбуком», «в дороге», «на даче с инструментом» и т.п.',
                ],
            ],
            'required' => ['available_minutes'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $minutes = (int) ($input['available_minutes'] ?? 0);
        if ($minutes <= 0) {
            return ToolResult::error('available_minutes должно быть положительным числом.');
        }

        $ctx = isset($input['context_description']) ? trim((string) $input['context_description']) : '';
        $ctxOrNull = $ctx !== '' ? $ctx : null;

        $em = $this->doctrine->getManager();
        /** @var TaskRepository $repo */
        $repo = $em->getRepository(Task::class);
        $tasks = $repo->findUnblockedForUser($user);

        if ($tasks === []) {
            return ToolResult::ok('Нет активных незаблокированных задач. Предложить нечего.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dto = $this->advisor->suggest($user, $minutes, $ctxOrNull, $tasks, $now);

        $lines = [];
        if ($dto->userSummary !== null) {
            $lines[] = $dto->userSummary;
        }

        if ($dto->noMatchReason !== null) {
            $lines[] = 'Ничего не подошло: ' . $dto->noMatchReason;
        }

        if ($dto->suggestions !== []) {
            $lines[] = '';
            $lines[] = 'Предложения (' . count($dto->suggestions) . '):';
            foreach ($dto->suggestions as $s) {
                $full = $repo->find($s->taskId);
                $title = $full?->getTitle() ?? '?';
                $parts = [
                    '#' . $s->order,
                    "[id:{$s->taskId}]",
                    $title,
                ];
                if ($s->estimatedMinutes !== null) {
                    $parts[] = "— {$s->estimatedMinutes}мин";
                }
                if ($s->tip !== null) {
                    $parts[] = '— ' . $s->tip;
                }
                $lines[] = implode(' ', $parts);
            }
            $lines[] = '';
            $lines[] = "Суммарно: {$dto->totalEstimatedMinutes}мин";
        }

        $this->logger->info('Assistant suggest_tasks', [
            'available_minutes' => $minutes,
            'context' => $ctxOrNull,
            'suggestions' => count($dto->suggestions),
        ]);

        return ToolResult::ok(
            $lines === [] ? 'Нет предложений.' : implode("\n", $lines),
            ['suggestions' => count($dto->suggestions)],
        );
    }
}
