<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\AI\DTO\PendingAction;
use App\AI\PendingActionStore;
use App\AI\TaskParser;
use App\Entity\Task;
use App\Entity\TaskContext;
use App\Entity\User;
use App\Enum\TaskSource;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class CreateTaskTool implements AssistantTool
{
    public function __construct(
        private readonly TaskParser $taskParser,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly PendingActionStore $pendingStore,
    ) {
    }

    public function getName(): string
    {
        return 'create_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Создать одну или несколько новых задач. Параметр `tasks` — массив:
        каждый элемент {raw_text} = исходный текст одной задачи. Поле `raw_text`
        в корне tool'а тоже поддерживается (для одной задачи, обратная совместимость).

        Логика:
        - 1 задача → создаётся сразу. Логика дубликатов как раньше (DUPLICATE_SKIPPED
          для exact, упоминание похожих, force=true).
        - 2+ задач → tool возвращает PENDING_CONFIRMATION:create_tasks_batch:<id>
          с превью. В reply сформулируй человеческое подтверждение и обязательно
          вставь маркер [CONFIRM:<id>] — он превратится в кнопки.

        Защита от дубликатов для одиночных:
        - Точный дубликат (title идентичен активной задаче, case-insensitive) — НЕ
          создаётся. Tool вернёт success=true, но content начнётся с «DUPLICATE_SKIPPED».
          В этом случае: в reply ЧЁТКО сообщи что задача уже есть, НЕ пиши «готово»,
          «создал», «добавил» — ничего не создавалось.
        - Похожие, но не идентичные — создаётся новая задача, в content упомянуты
          похожие. Передай пользователю что создал, и что похожие уже были.
        - `force=true` отключает проверку на точный дубликат.
        DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'description' => 'Список задач для создания. Если одна — создастся сразу. Если 2+ — пользователь подтверждает.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'raw_text' => ['type' => 'string', 'description' => 'Текст одной задачи'],
                        ],
                        'required' => ['raw_text'],
                    ],
                ],
                'raw_text' => [
                    'type' => 'string',
                    'description' => 'Альтернатива tasks для одиночной задачи (backward compat).',
                ],
                'force' => [
                    'type' => 'boolean',
                    'description' => 'Создать одиночную задачу даже если есть exact-дубликат. По умолчанию false.',
                ],
            ],
        ];
    }

    public function execute(User $user, array $input): ToolResult
    {
        // Унифицируем оба формата ввода: tasks[] и одиночный raw_text.
        $rawTexts = [];
        if (isset($input['tasks']) && is_array($input['tasks'])) {
            foreach ($input['tasks'] as $item) {
                if (is_array($item) && isset($item['raw_text'])) {
                    $t = trim((string) $item['raw_text']);
                    if ($t !== '') {
                        $rawTexts[] = $t;
                    }
                } elseif (is_string($item) && trim($item) !== '') {
                    // На случай если модель передала просто строки.
                    $rawTexts[] = trim($item);
                }
            }
        }
        if ($rawTexts === [] && isset($input['raw_text']) && is_string($input['raw_text'])) {
            $t = trim((string) $input['raw_text']);
            if ($t !== '') {
                $rawTexts[] = $t;
            }
        }

        if ($rawTexts === []) {
            return ToolResult::error('Нужен tasks (массив) или raw_text (строка)');
        }

        // 2+ задач → confirmation.
        if (count($rawTexts) >= 2) {
            return $this->createPendingBatch($user, $rawTexts);
        }

        $rawText = $rawTexts[0];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dto = $this->taskParser->parse($rawText, $user, $now);

        $em = $this->doctrine->getManager();
        $force = (bool) ($input['force'] ?? false);

        // Проверка на дубликаты — даже если force=true, всё равно находим
        // exact-совпадение по title (case-insensitive), чтобы не плодить
        // одинаковые задачи. force снимает только проверку по похожим.
        $exact = null;
        $similar = [];
        if (!$force) {
            [$exact, $similar] = $this->findDuplicatesSplit($user, $dto->title);
            if ($exact !== null) {
                return ToolResult::ok(
                    "DUPLICATE_SKIPPED: задача «{$exact->getTitle()}» уже есть в активных "
                    . "(id:{$exact->getId()->toRfc4122()}, статус: {$exact->getStatus()->value}). "
                    . 'Новая задача НЕ создана. Сообщи пользователю что такая задача уже есть, '
                    . 'и что если он хотел новую с уточнением (например «на даче») — пусть напишет '
                    . 'следующим сообщением с этим уточнением. НЕ пиши «готово», «создал», '
                    . '«добавил» — ничего не создавалось.',
                    ['created' => false],
                );
            }
        }

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
        if ($dto->remindBeforeDeadlineMinutes !== null) {
            $task->setRemindBeforeDeadlineMinutes($dto->remindBeforeDeadlineMinutes);
        }
        if ($dto->reminderIntervalMinutes !== null) {
            $task->setReminderIntervalMinutes($dto->reminderIntervalMinutes);
        }
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

        $this->logger->info('Creating task (Assistant)', [
            'task_id' => $task->getId()->toRfc4122(),
            'title' => $task->getTitle(),
            'deadline' => $task->getDeadline()?->format('c'),
            'priority' => $task->getPriority()->value,
            'remind_before_deadline_minutes' => $task->getRemindBeforeDeadlineMinutes(),
            'reminder_interval_minutes' => $task->getReminderIntervalMinutes(),
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

        $content = implode(', ', $parts);
        if ($similar !== []) {
            $simLines = ["Info для reply: уже существуют похожие (не идентичные) задачи:"];
            foreach ($similar as $t) {
                $simLines[] = "- [id:{$t->getId()->toRfc4122()}] {$t->getTitle()}";
            }
            $simLines[] = 'В reply КОНСТАТИРУЙ что создал новую и что есть похожие — НЕ задавай '
                . 'вопросов «это одна задача или разные?» / «нужно ли объединить?». У тебя '
                . 'нет памяти, ответ пользователя на такой вопрос ты не увидишь в контексте. '
                . 'Если захочет объединить — сам напишет «удали задачу X» или «обнови X».';
            $content .= "\n\n" . implode("\n", $simLines);
        }

        return ToolResult::ok(
            $content,
            ['task_id' => $task->getId()->toRfc4122(), 'created' => true],
        );
    }

    /**
     * Разделяет кандидатов на точный дубликат (title идентичен
     * case-insensitive — ровно один, если есть) и похожие (совпадает
     * ≥ половины keywords). Exact-дубликат → «не создавать», похожие →
     * «создавать, но упомянуть».
     *
     * @return array{0: Task|null, 1: Task[]} [exact, similar]
     */
    /**
     * @param string[] $rawTexts
     */
    private function createPendingBatch(User $user, array $rawTexts): ToolResult
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $userTz = new \DateTimeZone($user->getTimezone());
        // Парсим каждую через TaskParser, чтобы получить понятные title для preview.
        $previews = [];
        foreach ($rawTexts as $rawText) {
            try {
                $dto = $this->taskParser->parse($rawText, $user, $now);
                $line = $dto->title;
                if ($dto->deadline !== null) {
                    $line .= ' (' . $dto->deadline->setTimezone($userTz)->format('Y-m-d H:i') . ')';
                } else {
                    $line .= ' (без дедлайна)';
                }
                $previews[] = $line;
            } catch (\Throwable $e) {
                $this->logger->warning('CreateTaskTool: parser failed for batch item', [
                    'raw_text' => mb_substr($rawText, 0, 100),
                    'error' => $e->getMessage(),
                ]);
                $previews[] = mb_substr($rawText, 0, 60);
            }
        }

        $description = "Будут созданы:\n" . implode("\n", array_map(
            fn (int $i, string $line) => ($i + 1) . '. ' . $line,
            array_keys($previews),
            $previews,
        ));

        $action = new PendingAction(
            userId: $user->getId()->toRfc4122(),
            actionType: 'create_tasks_batch',
            description: $description,
            payload: ['raw_texts' => array_values($rawTexts)],
            createdAt: $now,
        );
        $confirmId = $this->pendingStore->create($user, $action);

        $this->logger->info('CreateTaskTool: pending batch', [
            'user_id' => $user->getId()->toRfc4122(),
            'count' => count($rawTexts),
            'confirmation_id' => $confirmId,
        ]);

        return ToolResult::ok(
            "PENDING_CONFIRMATION:create_tasks_batch:{$confirmId}\n{$description}",
            ['confirmation_id' => $confirmId, 'pending' => true],
        );
    }

    private function findDuplicatesSplit(User $user, string $title): array
    {
        $titleLc = mb_strtolower(trim($title));

        $keywords = $this->extractKeywords($title);
        if ($keywords === []) {
            // title без значимых слов — только exact-проверка по полному тексту
            $em = $this->doctrine->getManager();
            $exact = $em->getRepository(Task::class)->createQueryBuilder('t')
                ->andWhere('t.user = :user')
                ->andWhere('t.status IN (:open)')
                ->andWhere('LOWER(t.title) = :title')
                ->setParameter('user', $user)
                ->setParameter('open', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS, TaskStatus::SNOOZED])
                ->setParameter('title', $titleLc)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            return [$exact, []];
        }

        $em = $this->doctrine->getManager();
        $qb = $em->getRepository(Task::class)->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.status IN (:open)')
            ->setParameter('user', $user)
            ->setParameter('open', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS, TaskStatus::SNOOZED]);

        $orParts = [];
        foreach ($keywords as $i => $kw) {
            $orParts[] = "LOWER(t.title) LIKE :kw{$i}";
            $qb->setParameter("kw{$i}", '%' . $kw . '%');
        }
        $qb->andWhere('(' . implode(' OR ', $orParts) . ')');

        $candidates = $qb->getQuery()->getResult();

        $exact = null;
        $similar = [];
        $needHits = max(1, (int) ceil(count($keywords) / 2));
        foreach ($candidates as $c) {
            $cTitleLc = mb_strtolower($c->getTitle());
            if ($cTitleLc === $titleLc) {
                $exact = $c;
                continue;
            }
            $hits = 0;
            foreach ($keywords as $kw) {
                if (mb_strpos($cTitleLc, $kw) !== false) {
                    $hits++;
                }
            }
            if ($hits >= $needHits) {
                $similar[] = $c;
            }
        }

        return [$exact, $similar];
    }

    /**
     * Извлекает 2-3 ключевых корня из title, отсеивая короткие слова и
     * типовые глаголы-команды («купить», «сделать», «позвонить»,
     * предлоги). Стемминг — тот же «срежь последние 2 символа если >3».
     *
     * @return string[]
     */
    private function extractKeywords(string $title): array
    {
        $stopwords = [
            'купить', 'сделать', 'позвонить', 'написать', 'забрать', 'отправить',
            'проверить', 'разобрать', 'сходить', 'съездить', 'посмотреть',
            'прочитать', 'встретиться', 'забронировать',
            'для', 'про', 'при', 'над', 'под', 'без', 'его', 'это',
        ];
        $words = preg_split('/[\s,\.\-:;!?()\[\]«»"\']+/u', mb_strtolower($title)) ?: [];
        $result = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 4) {
                continue;
            }
            if (in_array($w, $stopwords, true)) {
                continue;
            }
            $root = mb_strlen($w) > 3 ? mb_substr($w, 0, -2) : $w;
            if ($root === '' || in_array($root, $result, true)) {
                continue;
            }
            $result[] = $root;
            if (count($result) >= 3) {
                break;
            }
        }

        return $result;
    }
}
