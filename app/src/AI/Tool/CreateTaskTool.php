<?php

declare(strict_types=1);

namespace App\AI\Tool;

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
    ) {
    }

    public function getName(): string
    {
        return 'create_task';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
        Создать новую задачу для пользователя. Используй когда пользователь описывает
        что-то, что ему нужно сделать. Извлекай структуру (заголовок, дедлайн,
        приоритет, контексты) из исходного текста — TaskParser сделает это автоматически.
        Передавай raw_text = полный текст пользователя с описанием задачи.

        Защита от дубликатов (автоматическая, вопросов пользователю не задавай):
        - Точный дубликат (title идентичен активной задаче, case-insensitive) — НЕ
          создаётся. Tool вернёт success=true, но content начнётся с «DUPLICATE_SKIPPED».
          В этом случае: в reply ЧЁТКО сообщи что задача уже есть, НЕ пиши «готово»,
          «создал», «добавил» — ничего не создавалось. Пример: «Такая задача уже
          есть. Если хотел отдельную (например "молоко на даче") — напиши с уточнением».
        - Похожие, но не идентичные (отличается уточнением: «молоко» vs «молоко на
          даче») — создаётся новая задача, в content будут упомянуты похожие.
          Передай пользователю что создал, и что похожие уже были — пусть сам решит
          в следующем сообщении если захочет объединить.
        - `force=true` отключает проверку на точный дубликат (создаст даже при совпадении
          title). Используй только если пользователь явно подтвердил в текущем сообщении.
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
                'force' => [
                    'type' => 'boolean',
                    'description' => 'Создать даже если есть похожая активная задача. По умолчанию false.',
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
