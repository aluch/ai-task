<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\ClaudeResponse;
use App\AI\DTO\ParsedTaskDTO;
use App\Entity\TaskContext;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Repository\TaskContextRepository;
use Psr\Log\LoggerInterface;

class TaskParser
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly TaskContextRepository $contexts,
        private readonly LoggerInterface $logger,
        private readonly string $model,
    ) {
    }

    public function parse(string $userText, User $user, \DateTimeImmutable $now): ParsedTaskDTO
    {
        $userTz = new \DateTimeZone($user->getTimezone());
        $nowLocal = $now->setTimezone($userTz);
        $allContexts = $this->contexts->findAll();

        $systemPrompt = $this->buildSystemPrompt($nowLocal, $userTz, $allContexts);

        $response = $this->claude->createMessage(
            systemPrompt: $systemPrompt,
            messages: [
                ['role' => 'user', 'content' => "<user_message>\n{$userText}\n</user_message>"],
            ],
            model: $this->model,
            maxTokens: 1024,
            temperature: 0.0,
        );

        return $this->parseResponse($response, $userTz, $allContexts, $userText);
    }

    /**
     * @param TaskContext[] $allContexts
     */
    private function buildSystemPrompt(
        \DateTimeImmutable $nowLocal,
        \DateTimeZone $userTz,
        array $allContexts,
    ): string {
        $contextList = '';
        foreach ($allContexts as $ctx) {
            $desc = $ctx->getDescription() ? " — {$ctx->getDescription()}" : '';
            $contextList .= "  - {$ctx->getCode()}: {$ctx->getLabel()}{$desc}\n";
        }

        $nowStr = $nowLocal->format('Y-m-d H:i (l)');
        $tzName = $userTz->getName();

        return <<<PROMPT
        Ты извлекаешь структуру задачи из свободного текста пользователя.

        Текущее время пользователя: {$nowStr}
        Часовой пояс: {$tzName}

        Доступные контексты (выбирай ТОЛЬКО из этого списка, не придумывай новые):
        {$contextList}
        Отвечай строго JSON без markdown-обёрток, без пояснений, без preamble. Только JSON-объект:

        {
          "title": "Краткий заголовок задачи, 3-8 слов, начинается с глагола в инфинитиве",
          "description": "Полное описание если оно добавляет контекст к title, иначе null",
          "deadline_iso": "ISO 8601 с timezone пользователя, например 2026-04-16T19:00:00+03:00, или null",
          "deadline_reasoning": "Почему выбран такой дедлайн",
          "estimated_minutes": 30,
          "priority": "low|medium|high|urgent",
          "priority_reasoning": "Короткое обоснование приоритета",
          "context_codes": ["needs_internet", "quick"],
          "remind_before_deadline_minutes": 60,
          "notes": "Что непонятно или спорно в запросе — если всё ясно, null"
        }

        Правила:

        1. Title — краткий, императивный, в инфинитиве, 3-8 слов. НЕ копируй весь текст.
           «Необходимо купить билеты на концерт Сашки в АЛСО» → «Купить билеты на концерт Сашки в АЛСО»

        2. Description — если пользователь написал больше одного предложения или указал
           детали (время выхода, время возвращения, подробности маршрута, количество
           предметов, номера документов, адреса и т.д.) — ОБЯЗАТЕЛЬНО сохрани эти детали
           в description. Title — это 3-8 слов, description — все остальные подробности.
           Null ТОЛЬКО если исходный текст укладывается в title целиком.

        3. Deadline (ISO 8601 с timezone пользователя):
           - «сегодня вечером» → 19:00 текущего дня
           - «завтра утром», «с утра» → 09:00 следующего дня
           - «завтра» БЕЗ указания конкретного часа → 18:00 следующего дня (конец рабочего дня).
             Утро (09:00) ТОЛЬКО если явно сказано «завтра утром» или «с утра».
           - «на этой неделе» → пятница 18:00
           - Конкретная дата без времени → 18:00 в указанный день
           - Нет упоминания времени → null
           ВАЖНО: используй текущее время пользователя из этого промпта.

        4. Priority:
           - urgent: «срочно», «немедленно», «сегодня до N часов», восклицательные знаки с усилением
           - high: явное указание срочности ИЛИ дедлайн в ближайшие 24 часа
           - low: без срочности и далёкий дедлайн
           - medium: по умолчанию

        5. Estimated_minutes — ВСЕГДА старайся оценить время. Правила:
           - Если пользователь явно описал длительность — используй её.
             Пример: «тренировка 1 час» + «полчаса на дорогу туда и обратно» = 120 минут.
           - Если нет явной информации — оценивай по здравому смыслу:
             купить продукты ~30 мин, позвонить ~10 мин, сходить в МФЦ ~60 мин,
             написать отчёт ~120 мин, разобрать почту ~20 мин, уборка квартиры ~90 мин.
           - null только если задача настолько абстрактна, что оценить невозможно.

        6. Context_codes — ТОЛЬКО из списка выше. Можно несколько. Пустой массив если ничего не подходит.
           ВАЖНО: контекст «quick» означает задачу до 15 минут. Ставь quick ТОЛЬКО если
           estimated_minutes ≤ 15. Если задача занимает больше — НЕ ставь quick, даже если
           она кажется простой.

        7. Remind_before_deadline_minutes — за сколько минут до дедлайна напомнить.

           ГЛАВНОЕ правило: если пользователь ЯВНО просит напомнить («напомни мне»,
           «напомни за час», «предупреди», «дай знать за 10 минут»), ВСЕГДА ставь это
           поле — независимо от priority. Это самый частый сценарий, и игнорировать
           просьбу пользователя нельзя. Извлекай время из запроса:
           - «за час» / «за 1 час» → 60
           - «за 10 минут» / «за 10 мин» → 10
           - «за полчаса» / «за 30 минут» → 30
           - «за 2 часа» → 120
           - «за день» / «за сутки» → 1440
           - «заранее» без числа → 60 по умолчанию
           deadline при этом должен быть, иначе напоминать не от чего.

           Если пользователь НЕ просил явно, авто-ставь только при deadline + priority
           ∈ (high, urgent):
           - urgent + дедлайн сегодня → 30
           - high + дедлайн сегодня → 60
           - high + дедлайн завтра или позже → 120
           - urgent + дедлайн завтра или позже → 60
           - Если estimated_minutes > 60 (задача длинная), увеличь напоминание на
             estimated_minutes, чтобы пользователь успел начать заранее.

           В остальных случаях — null.
        PROMPT;
    }

    /**
     * @param TaskContext[] $allContexts
     */
    private function parseResponse(
        ClaudeResponse $response,
        \DateTimeZone $userTz,
        array $allContexts,
        string $originalText,
    ): ParsedTaskDTO {
        $json = $this->extractJson($response->text);

        if ($json === null) {
            $this->logger->warning('TaskParser: failed to extract JSON from response', [
                'response_text' => mb_substr($response->text, 0, 500),
            ]);

            return new ParsedTaskDTO(title: mb_substr($originalText, 0, 255));
        }

        $this->logger->debug('TaskParser: parsed response', [
            'deadline_reasoning' => $json['deadline_reasoning'] ?? null,
            'priority_reasoning' => $json['priority_reasoning'] ?? null,
            'notes' => $json['notes'] ?? null,
        ]);

        $title = mb_substr(trim($json['title'] ?? $originalText), 0, 255);
        if ($title === '') {
            $title = mb_substr($originalText, 0, 255);
        }

        $description = isset($json['description']) && is_string($json['description']) && $json['description'] !== ''
            ? $json['description']
            : null;

        $deadline = $this->parseDeadline($json['deadline_iso'] ?? null, $userTz);
        $estimatedMinutes = isset($json['estimated_minutes']) && is_int($json['estimated_minutes'])
            ? $json['estimated_minutes']
            : null;

        $priority = TaskPriority::tryFrom($json['priority'] ?? '') ?? TaskPriority::MEDIUM;

        $validCodes = array_map(fn (TaskContext $c) => $c->getCode(), $allContexts);
        $contextCodes = array_values(array_intersect($json['context_codes'] ?? [], $validCodes));

        $notes = isset($json['notes']) && is_string($json['notes']) && $json['notes'] !== ''
            ? $json['notes']
            : null;

        $remindBefore = null;
        if (isset($json['remind_before_deadline_minutes']) && is_int($json['remind_before_deadline_minutes'])) {
            $candidate = $json['remind_before_deadline_minutes'];
            // Санитизация: требуется только deadline — приоритет может быть любым,
            // потому что пользователь мог явно попросить напомнить даже у medium-
            // задачи («напомни мне за час» про домашку). Правила «без явного запроса
            // только для high/urgent» — на стороне промпта.
            if ($candidate > 0 && $deadline !== null) {
                $remindBefore = $candidate;
            }
        }

        $this->logger->info('TaskParser: parsed task', [
            'title' => $title,
            'deadline' => $deadline?->format('c'),
            'priority' => $priority->value,
            'remind_before_deadline_minutes' => $remindBefore,
            'ai_remind_before_raw' => $json['remind_before_deadline_minutes'] ?? null,
        ]);

        return new ParsedTaskDTO(
            title: $title,
            description: $description,
            deadline: $deadline,
            estimatedMinutes: $estimatedMinutes,
            priority: $priority,
            contextCodes: $contextCodes,
            parserNotes: $notes,
            remindBeforeDeadlineMinutes: $remindBefore,
        );
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Попытка 1: прямой decode
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }

        // Попытка 2: извлечь JSON-блок из markdown ```json ... ```
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m) === 1) {
            $data = json_decode($m[1], true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Попытка 3: найти первый { ... } блок
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

    private function parseDeadline(?string $iso, \DateTimeZone $userTz): ?\DateTimeImmutable
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($iso);
            $utc = $dt->setTimezone(new \DateTimeZone('UTC'));

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($utc <= $now) {
                $this->logger->warning('TaskParser: ignoring deadline in the past', [
                    'deadline_iso' => $iso,
                    'now_utc' => $now->format('c'),
                ]);

                return null;
            }

            return $utc;
        } catch (\Exception $e) {
            $this->logger->warning('TaskParser: failed to parse deadline', [
                'deadline_iso' => $iso,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
