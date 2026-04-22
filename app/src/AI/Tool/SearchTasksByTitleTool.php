<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\Tool\Support\TaskLookup;
use App\Entity\User;

class SearchTasksByTitleTool implements AssistantTool
{
    private const DEFAULT_LIMIT = 5;

    public function __construct(
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
        Найти задачи пользователя по смыслу запроса. Используй когда нужно уточнить
        о какой именно задаче идёт речь (есть несколько похожих) или показать
        релевантные «по теме».

        Под капотом — семантический матчер через Haiku: понимает русскую морфологию
        (падежи, времена, разные части речи: «пополнение» ≈ «пополнить», «стрижку»
        ≈ «стрижка»). Ищет среди всех задач пользователя (любой статус). Возвращает
        до 5 наиболее релевантных.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Поисковый запрос на естественном языке.',
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

        // По всем статусам — полезно когда пользователь ищет в т.ч. done/cancelled.
        $matches = $this->lookup->findByQuery($user, $query, [], self::DEFAULT_LIMIT);

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
