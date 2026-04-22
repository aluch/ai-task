<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Exception\ClaudeRateLimitException;
use App\AI\Exception\ClaudeTransientException;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Семантический поиск задач по пользовательскому запросу через Haiku.
 *
 * Зачем: примитивный ILIKE-стемминг (отрезать 2 символа) плохо ловит
 * русскую морфологию — «пополнение счёта» и «Пополнить счёт ИИС» не
 * совпадают (разные части речи), «стрижку» vs «стрижка» — разные окончания.
 *
 * Стратегия: грузим все открытые задачи пользователя (обычно <50), даём
 * Haiku список title+id и запрос — она возвращает отсортированный по
 * релевантности список. Дёшево (~$0.001 за вызов), быстро (~1-2s).
 *
 * Fallback: если Claude недоступен (429/5xx) — вызывающий код должен
 * откатиться на стемминг-ILIKE через TaskLookup::resolveByStem.
 */
class TaskMatcher
{
    public function __construct(
        private readonly ClaudeClient $client,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly string $model,
    ) {
    }

    /**
     * Находит задачи по семантическому сходству названия. Возвращает
     * 0-N наиболее релевантных Task'ов, отсортированных по релевантности.
     *
     * @param TaskStatus[]|null $statusFilter null → все статусы; иначе фильтр
     * @return Task[]
     * @throws ClaudeRateLimitException|ClaudeTransientException при падении Claude
     */
    public function findByQuery(
        User $user,
        string $query,
        ?array $statusFilter = null,
        int $limit = 3,
    ): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $em = $this->doctrine->getManager();
        $qb = $em->getRepository(Task::class)->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC');

        if ($statusFilter !== null && $statusFilter !== []) {
            $qb->andWhere('t.status IN (:statuses)')
                ->setParameter('statuses', $statusFilter);
        }

        /** @var Task[] $all */
        $all = $qb->getQuery()->getResult();

        if ($all === []) {
            return [];
        }
        // Одна задача — Haiku не нужна, это та самая задача если запрос
        // хоть как-то относится к делу (вопрос уточнения стоит на стороне
        // Ассистента: он либо действует, либо уточняет).
        if (count($all) === 1) {
            return [$all[0]];
        }

        $start = microtime(true);
        $ids = $this->askMatcher($query, $all);
        $elapsed = round(microtime(true) - $start, 2);

        $byId = [];
        foreach ($all as $t) {
            $byId[$t->getId()->toRfc4122()] = $t;
        }

        $matched = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $matched[] = $byId[$id];
            }
            if (count($matched) >= $limit) {
                break;
            }
        }

        $this->logger->info('TaskMatcher result', [
            'query' => $query,
            'candidates' => count($all),
            'matched_ids' => array_map(fn (Task $t) => $t->getId()->toRfc4122(), $matched),
            'elapsed' => $elapsed,
        ]);

        return $matched;
    }

    /**
     * @param Task[] $tasks
     * @return string[] task_id в порядке релевантности
     */
    private function askMatcher(string $query, array $tasks): array
    {
        $lines = [];
        foreach ($tasks as $i => $t) {
            $lines[] = sprintf(
                '%d. [%s] %s',
                $i + 1,
                $t->getId()->toRfc4122(),
                $t->getTitle(),
            );
        }
        $taskList = implode("\n", $lines);

        $systemPrompt = <<<'PROMPT'
        Ты помогаешь сопоставить пользовательский поисковый запрос с задачами из его списка.
        Запрос может быть на русском, в любых формах слов (разные падежи, времена, части речи), с опечатками.
        Задачи имеют названия — их нужно сравнить с запросом по СМЫСЛУ, а не по точному совпадению.

        Отвечай строго JSON без markdown, без пояснений:
        {"task_ids": ["uuid1", "uuid2"]}

        Правила:
        - Возвращай от 0 до 3 task_ids, отсортированных по убыванию релевантности.
        - task_id — ПОЛНЫЙ UUID ровно как в списке (формат 019d...).
        - Если ни одна задача не подходит по смыслу — пустой массив {"task_ids": []}.
        - «Пополнение счёта» и «Пополнить счёт» — это одно и то же действие, оба релевантны.
        - «Стрижку» и «Стрижка» — одна задача, разные формы слова.
        - Если запрос слишком общий и подходят несколько — верни все подходящие (до 3).
        PROMPT;

        $userPrompt = "Запрос пользователя: \"{$query}\"\n\nСписок задач:\n{$taskList}";

        $response = $this->client->createMessage(
            systemPrompt: $systemPrompt,
            messages: [['role' => 'user', 'content' => $userPrompt]],
            model: $this->model,
            maxTokens: 256,
            temperature: 0.0,
        );

        $json = $this->extractJson($response->text);
        if ($json === null || !isset($json['task_ids']) || !is_array($json['task_ids'])) {
            $this->logger->warning('TaskMatcher: failed to parse Haiku response', [
                'response' => mb_substr($response->text, 0, 300),
            ]);

            return [];
        }

        $result = [];
        foreach ($json['task_ids'] as $id) {
            if (is_string($id) && $id !== '') {
                $result[] = $id;
            }
        }

        return $result;
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
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
