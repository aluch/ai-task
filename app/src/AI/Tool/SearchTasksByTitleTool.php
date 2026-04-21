<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;

class SearchTasksByTitleTool implements AssistantTool
{
    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TaskLookup $lookup,
    ) {
    }

    public function getName(): string
    {
        return 'search_tasks_by_title';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Найти задачи пользователя по части названия или описания. Используй когда нужно
        уточнить о какой именно задаче идёт речь (есть несколько похожих) или просто
        показать релевантные «по теме».

        Ищет с морфо-стеммингом (обрезает последние 2 символа слов >3). Возвращает до
        10 совпадений в любом статусе, с id, title, статусом и дедлайном.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Часть названия или описания.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('query обязателен.');
        }

        $root = $this->lookup->toSearchRoot($query);
        $em = $this->doctrine->getManager();
        $matches = $em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('LOWER(t.title) LIKE :q OR LOWER(t.description) LIKE :q')
            ->setParameter('user', $user)
            ->setParameter('q', '%' . $root . '%')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(self::DEFAULT_LIMIT)
            ->getQuery()
            ->getResult();

        if ($matches === []) {
            return ToolResult::ok("Ничего не найдено по запросу «{$query}».");
        }

        $userTz = new \DateTimeZone($user->getTimezone());
        $lines = ["Найдено по «{$query}» (" . count($matches) . '):'];
        foreach ($matches as $i => $t) {
            $parts = [
                ($i + 1) . '.',
                "[id:{$t->getId()->toRfc4122()}]",
                $t->getTitle(),
                "({$t->getStatus()->value})",
            ];
            if ($t->getDeadline() !== null) {
                $parts[] = 'дедлайн: ' . $t->getDeadline()->setTimezone($userTz)->format('Y-m-d H:i');
            }
            $lines[] = implode(' ', $parts);
        }

        return ToolResult::ok(implode("\n", $lines), ['count' => count($matches)]);
    }
}
