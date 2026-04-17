<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\ClaudeResponse;
use App\AI\DTO\SuggestedTask;
use App\AI\DTO\TaskSuggestionDTO;
use App\Entity\Task;
use App\Entity\User;
use Psr\Log\LoggerInterface;

class TaskAdvisor
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly LoggerInterface $logger,
        private readonly string $model,
    ) {
    }

    /**
     * @param Task[] $tasks
     * @param string[] $excludeTaskIds
     */
    public function suggest(
        User $user,
        int $availableMinutes,
        ?string $contextDescription,
        array $tasks,
        \DateTimeImmutable $now,
        array $excludeTaskIds = [],
    ): TaskSuggestionDTO {
        if ($tasks === []) {
            return new TaskSuggestionDTO(
                suggestions: [],
                noMatchReason: 'У тебя нет открытых задач.',
            );
        }

        $userTz = new \DateTimeZone($user->getTimezone());
        $systemPrompt = $this->buildSystemPrompt(
            $now->setTimezone($userTz),
            $userTz,
            $availableMinutes,
            $contextDescription,
            $excludeTaskIds,
        );

        $userPrompt = $this->buildUserPrompt($tasks, $now, $userTz);

        $response = $this->claude->createMessage(
            systemPrompt: $systemPrompt,
            messages: [['role' => 'user', 'content' => $userPrompt]],
            model: $this->model,
            maxTokens: 2048,
            temperature: 0.3,
        );

        $validIds = array_map(fn (Task $t) => $t->getId()->toRfc4122(), $tasks);

        return $this->parseResponse($response, $validIds, $availableMinutes);
    }

    private function buildSystemPrompt(
        \DateTimeImmutable $nowLocal,
        \DateTimeZone $userTz,
        int $availableMinutes,
        ?string $contextDescription,
        array $excludeTaskIds,
    ): string {
        $nowStr = $nowLocal->format('Y-m-d H:i (l)');
        $tzName = $userTz->getName();

        $contextLine = $contextDescription !== null && $contextDescription !== ''
            ? "Контекст пользователя: {$contextDescription}"
            : 'Контекст не указан — можно предлагать любые задачи по другим критериям.';

        $excludedLine = '';
        if ($excludeTaskIds !== []) {
            $excludedLine = "\n\nНЕ предлагай эти task_id (уже отвергнуты пользователем):\n" .
                implode("\n", array_map(fn ($id) => "  - {$id}", $excludeTaskIds));
        }

        return <<<PROMPT
        Ты AI-помощник по управлению задачами. Пользователь сообщил, сколько у него свободного времени и где он находится. Твоя задача — подобрать из его открытых задач оптимальный набор, который он реально сможет сделать прямо сейчас.

        Текущее время: {$nowStr}
        Часовой пояс: {$tzName}
        Доступное время: {$availableMinutes} минут
        {$contextLine}{$excludedLine}

        Отвечай строго JSON без markdown-обёрток, без пояснений, без preamble. Только JSON-объект:

        {
          "reasoning": "Почему выбрал именно эти задачи в этом порядке",
          "suggestions": [
            {
              "task_id": "019d9774-...",
              "order": 1,
              "tip": "Короткий практический совет, или null",
              "estimated_minutes": 20
            }
          ],
          "total_estimated_minutes": 35,
          "no_match_reason": null
        }

        Если ни одна задача не подходит — suggestions: [] и no_match_reason с объяснением.

        Правила подбора:

        1. Приоритет и срочность. Задачи с приближающимся дедлайном (ближайшие 24-48 часов) и высоким priority — в первую очередь. urgent или is_overdue=true — почти всегда должна попасть в предложение (если контекст подходит).

        2. Уложиться во время. Сумма estimated_minutes не должна превышать доступное время. Если у задачи нет оценки — прикинь по смыслу. Лучше предложить на 10-15% меньше, чем перегрузить.

        3. Контекст места. Если «дома» — не предлагай задачи с контекстами outdoor, at_dacha, at_office. Если «на улице» — не предлагай задачи, требующие концентрации или стационарного интернета (focused обычно не ок на улице). Если контекст не указан — предлагай любые.

        4. Группировка по месту/маршруту. Если несколько задач можно выполнить за один выход (соседние места, по пути) — предложи их вместе и скажи об этом в reasoning и tip. Ищи совпадения в description (адреса, «рядом», «по пути», «заодно»).

        5. Зависимости. Заблокированные задачи в списке не должно быть. Если вдруг попадёт — игнорируй.

        6. Порядок выполнения. Предлагай в оптимальной последовательности: сначала по пути → потом дом, либо короткие «разогревочные» → потом сложные. Думай как человек, планирующий свой день.

        7. Совет (tip). К каждой задаче — короткий практический совет, если есть что сказать. Если самоочевидно — null. Не повторяй title.

        8. Пустой результат. Если ни одна задача не подходит — пустой suggestions и объяснение в no_match_reason: «все задачи требуют выхода из дома, а ты дома», «у тебя 30 минут, но все задачи длиннее часа».

        9. Переполнение. Если задач подходит больше чем влезает — выбери самые важные, а в reasoning упомяни сколько ещё подходит но не влезло.
        PROMPT;
    }

    /**
     * @param Task[] $tasks
     */
    private function buildUserPrompt(array $tasks, \DateTimeImmutable $now, \DateTimeZone $userTz): string
    {
        $items = [];
        foreach ($tasks as $task) {
            $deadline = $task->getDeadline();
            $isOverdue = $deadline !== null && $deadline < $now;

            $items[] = [
                'id' => $task->getId()->toRfc4122(),
                'title' => $task->getTitle(),
                'description' => $task->getDescription(),
                'deadline_iso' => $deadline?->setTimezone($userTz)->format(\DateTimeInterface::ATOM),
                'estimated_minutes' => $task->getEstimatedMinutes(),
                'priority' => $task->getPriority()->value,
                'context_codes' => array_map(fn ($c) => $c->getCode(), $task->getContexts()->toArray()),
                'created_at_iso' => $task->getCreatedAt()->setTimezone($userTz)->format(\DateTimeInterface::ATOM),
                'is_overdue' => $isOverdue,
            ];
        }

        $json = json_encode($items, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);

        return "<available_tasks>\n{$json}\n</available_tasks>";
    }

    /**
     * @param string[] $validIds
     */
    private function parseResponse(ClaudeResponse $response, array $validIds, int $availableMinutes): TaskSuggestionDTO
    {
        $json = $this->extractJson($response->text);

        if ($json === null) {
            $this->logger->warning('TaskAdvisor: failed to extract JSON from response', [
                'response_text' => mb_substr($response->text, 0, 500),
            ]);

            return new TaskSuggestionDTO(
                suggestions: [],
                noMatchReason: 'Не получилось обработать ответ AI, попробуй ещё раз.',
            );
        }

        $reasoning = isset($json['reasoning']) && is_string($json['reasoning']) && $json['reasoning'] !== ''
            ? $json['reasoning']
            : null;

        $noMatchReason = isset($json['no_match_reason']) && is_string($json['no_match_reason']) && $json['no_match_reason'] !== ''
            ? $json['no_match_reason']
            : null;

        $rawSuggestions = $json['suggestions'] ?? [];
        if (!is_array($rawSuggestions)) {
            $rawSuggestions = [];
        }

        $suggestions = [];
        $totalMinutes = 0;
        $validIdsSet = array_flip($validIds);

        foreach ($rawSuggestions as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $taskId = $raw['task_id'] ?? null;
            if (!is_string($taskId) || !isset($validIdsSet[$taskId])) {
                $this->logger->warning('TaskAdvisor: suggestion references unknown task_id', [
                    'task_id' => $taskId,
                ]);
                continue;
            }

            $order = isset($raw['order']) && is_int($raw['order']) ? $raw['order'] : count($suggestions) + 1;
            $tip = isset($raw['tip']) && is_string($raw['tip']) && $raw['tip'] !== '' ? $raw['tip'] : null;
            $estimatedMinutes = isset($raw['estimated_minutes']) && is_int($raw['estimated_minutes'])
                ? $raw['estimated_minutes']
                : null;

            $suggestions[] = new SuggestedTask(
                taskId: $taskId,
                order: $order,
                tip: $tip,
                estimatedMinutes: $estimatedMinutes,
            );

            if ($estimatedMinutes !== null) {
                $totalMinutes += $estimatedMinutes;
            }
        }

        // Сортировка по order
        usort($suggestions, fn (SuggestedTask $a, SuggestedTask $b) => $a->order <=> $b->order);

        if ($totalMinutes > $availableMinutes && $suggestions !== []) {
            $this->logger->warning('TaskAdvisor: suggestion exceeds available time, trimming', [
                'total' => $totalMinutes,
                'available' => $availableMinutes,
            ]);

            $trimmed = [];
            $acc = 0;
            foreach ($suggestions as $s) {
                $est = $s->estimatedMinutes ?? 0;
                if ($acc + $est > $availableMinutes) {
                    break;
                }
                $trimmed[] = $s;
                $acc += $est;
            }
            $suggestions = $trimmed;
            $totalMinutes = $acc;
        }

        $this->logger->info('TaskAdvisor: suggestion', [
            'count' => count($suggestions),
            'total_minutes' => $totalMinutes,
            'reasoning' => $reasoning,
        ]);

        return new TaskSuggestionDTO(
            suggestions: $suggestions,
            reasoning: $reasoning,
            totalEstimatedMinutes: $totalMinutes,
            noMatchReason: $suggestions === [] ? ($noMatchReason ?? 'AI не нашёл подходящих задач.') : null,
        );
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m) === 1) {
            $data = json_decode($m[1], true);
            if (is_array($data)) {
                return $data;
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}
