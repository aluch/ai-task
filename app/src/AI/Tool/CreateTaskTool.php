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

        Защита от дубликатов: перед созданием делается проверка на похожую активную
        задачу по первым значимым словам. Если нашлась — вернётся success=false со
        списком совпадений, и тебе нужно спросить у пользователя — создать новую или
        использовать update_task для существующей. Чтобы явно создать несмотря на
        похожие — передай force=true.
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
        if (!$force) {
            $dup = $this->findDuplicates($user, $dto->title);
            if ($dup !== []) {
                $lines = [
                    "Уже есть похожие активные задачи по «{$dto->title}»:",
                ];
                foreach ($dup as $t) {
                    $lines[] = "- [id:{$t->getId()->toRfc4122()}] {$t->getTitle()} ({$t->getStatus()->value})";
                }
                $lines[] = 'Уточни у пользователя: создать новую (передай force=true), обновить существующую (update_task) или просто показать старую?';

                return ToolResult::error(implode("\n", $lines));
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

        return ToolResult::ok(
            implode(', ', $parts),
            ['task_id' => $task->getId()->toRfc4122()],
        );
    }

    /**
     * Быстрая эвристика дубликата: берём 2-3 значимых слова из title
     * (отсеиваем короткие и типовые глаголы/предлоги), ищем активные
     * задачи с ILIKE по каждому корню. Совпадение ≥2 слов → кандидат.
     *
     * @return Task[]
     */
    private function findDuplicates(User $user, string $title): array
    {
        $keywords = $this->extractKeywords($title);
        if ($keywords === []) {
            return [];
        }

        $em = $this->doctrine->getManager();
        $qb = $em->getRepository(Task::class)->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.status IN (:open)')
            ->setParameter('user', $user)
            ->setParameter('open', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS, TaskStatus::SNOOZED]);

        // Если только одно значимое слово — требуем его совпадение.
        // Если два+ — любое совпадение кандидата + проверка на количество.
        $orParts = [];
        foreach ($keywords as $i => $kw) {
            $orParts[] = "LOWER(t.title) LIKE :kw{$i}";
            $qb->setParameter("kw{$i}", '%' . $kw . '%');
        }
        $qb->andWhere('(' . implode(' OR ', $orParts) . ')');

        $candidates = $qb->getQuery()->getResult();

        if (count($keywords) === 1) {
            return $candidates;
        }

        // Для многословных title: требуем что у кандидата совпадает
        // минимум половина keywords (округл. вверх) — так «Купить молоко»
        // и «Купить бумагу» не слипнутся, а «Купить молока в магазине»
        // и «купить молоко» — да.
        $needHits = (int) ceil(count($keywords) / 2);
        $filtered = [];
        foreach ($candidates as $c) {
            $titleLc = mb_strtolower($c->getTitle());
            $hits = 0;
            foreach ($keywords as $kw) {
                if (mb_strpos($titleLc, $kw) !== false) {
                    $hits++;
                }
            }
            if ($hits >= $needHits) {
                $filtered[] = $c;
            }
        }

        return $filtered;
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
