<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\ClaudeResponse;
use App\AI\DTO\SuggestedTask;
use App\AI\DTO\TaskSuggestionDTO;
use App\AI\Exception\ClaudeRateLimitException;
use App\AI\Exception\ClaudeTransientException;
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
        // System prompt — только стабильные инструкции и timezone (кешируется).
        // Per-request данные (время, available_minutes, контекст, excluded_ids,
        // список задач) — в user message.
        $systemPrompt = $this->buildSystemPrompt($userTz);

        $userPrompt = $this->buildUserPrompt(
            $tasks,
            $now,
            $userTz,
            $availableMinutes,
            $contextDescription,
            $excludeTaskIds,
        );

        $response = $this->callWithRetry($systemPrompt, $userPrompt);

        $validIds = array_map(fn (Task $t) => $t->getId()->toRfc4122(), $tasks);

        return $this->parseResponse($response, $validIds, $availableMinutes);
    }

    /**
     * Retry на 429/5xx от Anthropic — до 3 попыток. Клиентские 4xx не повторяем.
     */
    private function callWithRetry(string $systemPrompt, string $userPrompt): ClaudeResponse
    {
        $maxAttempts = 3;
        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->claude->createMessage(
                    systemPrompt: $systemPrompt,
                    messages: [['role' => 'user', 'content' => $userPrompt]],
                    model: $this->model,
                    maxTokens: 2048,
                    temperature: 0.3,
                    cacheSystem: true,
                );
            } catch (ClaudeRateLimitException $e) {
                $last = $e;
                $wait = $e->retryAfter ?? 5;
                $this->logger->warning('TaskAdvisor rate limited', ['wait' => $wait, 'attempt' => $attempt]);
                if ($attempt < $maxAttempts) {
                    sleep($wait);
                }
            } catch (ClaudeTransientException $e) {
                $last = $e;
                $wait = $attempt;
                $this->logger->warning('TaskAdvisor transient, retrying', ['wait' => $wait, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                if ($attempt < $maxAttempts) {
                    sleep($wait);
                }
            }
        }

        throw $last;
    }

    private function buildSystemPrompt(\DateTimeZone $userTz): string
    {
        $tzName = $userTz->getName();

        return <<<PROMPT
        Ты AI-помощник по управлению задачами. Пользователь сообщил, сколько у него свободного времени и где он находится. Твоя задача — подобрать из его открытых задач оптимальный набор, который он реально сможет сделать прямо сейчас.

        Часовой пояс пользователя: {$tzName}

        Per-request данные (текущее время, доступное время, контекст пользователя, отвергнутые task_ids, список задач) приходят в user-сообщении в тегах <context> / <available_tasks>.

        Отвечай строго JSON без markdown-обёрток, без пояснений, без preamble. Только JSON-объект:

        {
          "user_summary": "Короткое дружеское объяснение для пользователя — 1-3 предложения, разговорный тон, по-русски. БЕЗ перечисления отвергнутых задач. БЕЗ нумерации пунктов.",
          "internal_reasoning": "Подробный анализ для логов: что выбрано и почему, что отвергнуто и по каким причинам, дилеммы, сомнения. Пользователь это НЕ видит.",
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

        Про разделение user_summary и internal_reasoning:

        - user_summary — то, что увидит пользователь под списком. Человек хочет знать «что и почему выбрано», а не «что не вошло». Разговорно, живо, ≤ 300 символов.
          ✅ «Взял срочные outdoor-задачи — корм и штаны можно совместить в один выход.»
          ✅ «Дедлайн по билетам завтра, так что в первую очередь они. Остальное быстрое, должен успеть.»
          ❌ «Выбрал 2 задачи. Отверг дачные (пользователь дома), видеокурс (не влезает в 2 часа), компостер (низкий приоритет)...»
          ❌ «1) Важно потому что... 2) Потом потому что...» (не нумеровать)
          НЕ упоминай отвергнутые. НЕ перечисляй критерии явно. Это ОДНА живая реплика.

        - internal_reasoning — для логов/отладки. Можешь подробно: каждую задачу взвесил, отвергнутые с причинами, сомнения. Пользователь этого не читает.

        Правила подбора:

        1. Приоритет и срочность. Задачи с приближающимся дедлайном (ближайшие 24-48 часов) и высоким priority — в первую очередь. urgent или is_overdue=true — почти всегда должна попасть в предложение (если контекст подходит).

        2. Уложиться во время. Сумма estimated_minutes не должна превышать доступное время. Если у задачи нет оценки — прикинь по смыслу. Лучше предложить на 10-15% меньше, чем перегрузить.

        3. Контекст места. Пользователь пишет по-русски, задачи размечены машинными кодами. Сопоставляй так:
           - «дома» → задачи с at_home или без локационных контекстов; НЕ подходят outdoor, at_dacha, at_office
           - «на даче» → at_dacha; также общие outdoor-задачи, если могут быть сделаны на даче
           - «на улице», «в дороге», «иду» → outdoor; НЕ подходят focused (требует концентрации) и обычно needs_internet (без ноута/планшета сложно); at_home и at_office тоже не подходят
           - «в офисе», «на работе» → at_office; также focused и needs_internet
           - если контекст не указан → любые контексты допустимы, используй только приоритеты и дедлайны

        4. Группировка по месту/маршруту. Если несколько задач можно выполнить за один выход (соседние места, по пути) — предложи их вместе и скажи об этом в user_summary и tip. Ищи совпадения в description (адреса, «рядом», «по пути», «заодно»).

        5. Зависимости. Заблокированные задачи в списке не должно быть. Если вдруг попадёт — игнорируй.

        6. Порядок выполнения. Предлагай в оптимальной последовательности: сначала по пути → потом дом, либо короткие «разогревочные» → потом сложные. Думай как человек, планирующий свой день.

        7. Совет (tip). К каждой задаче — короткий практический совет, если есть что сказать. Если самоочевидно — null. Не повторяй title.

        8. Пустой результат. Если ни одна задача не подходит — пустой suggestions и объяснение в no_match_reason: «все задачи требуют выхода из дома, а ты дома», «у тебя 30 минут, но все задачи длиннее часа».

        9. Переполнение. Если задач подходит больше чем влезает — выбери самые важные. Упомяни сколько ещё подходит но не влезло — в internal_reasoning подробно, в user_summary максимум одной фразой.
        PROMPT;
    }

    /**
     * @param Task[] $tasks
     * @param string[] $excludeTaskIds
     */
    private function buildUserPrompt(
        array $tasks,
        \DateTimeImmutable $now,
        \DateTimeZone $userTz,
        int $availableMinutes,
        ?string $contextDescription,
        array $excludeTaskIds,
    ): string {
        $nowStr = $now->setTimezone($userTz)->format('Y-m-d H:i (l)');

        $contextLine = $contextDescription !== null && $contextDescription !== ''
            ? "Контекст пользователя: {$contextDescription}"
            : 'Контекст не указан — можно предлагать любые задачи по другим критериям.';

        $excludedLine = '';
        if ($excludeTaskIds !== []) {
            $excludedLine = "\nНЕ предлагай эти task_id (уже отвергнуты пользователем):\n" .
                implode("\n", array_map(fn ($id) => "  - {$id}", $excludeTaskIds));
        }

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

        return "<context>\nТекущее время: {$nowStr}\nДоступное время: {$availableMinutes} минут\n{$contextLine}{$excludedLine}\n</context>\n\n<available_tasks>\n{$json}\n</available_tasks>";
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

        $userSummary = $this->extractUserSummary($json);
        $internalReasoning = isset($json['internal_reasoning']) && is_string($json['internal_reasoning']) && $json['internal_reasoning'] !== ''
            ? $json['internal_reasoning']
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
            'user_summary' => $userSummary,
            'internal_reasoning' => $internalReasoning,
        ]);

        return new TaskSuggestionDTO(
            suggestions: $suggestions,
            userSummary: $userSummary,
            internalReasoning: $internalReasoning,
            totalEstimatedMinutes: $totalMinutes,
            noMatchReason: $suggestions === [] ? ($noMatchReason ?? 'AI не нашёл подходящих задач.') : null,
        );
    }

    private function extractUserSummary(array $json): ?string
    {
        $raw = $json['user_summary'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (mb_strlen($raw) > 300) {
            $this->logger->warning('AI did not comply with summary length limit', [
                'length' => mb_strlen($raw),
                'summary' => mb_substr($raw, 0, 100) . '…',
            ]);

            return mb_substr($raw, 0, 299) . '…';
        }

        return $raw;
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
