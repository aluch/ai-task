<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\AssistantResult;
use App\AI\Tool\ToolRegistry;
use App\Entity\User;
use Psr\Log\LoggerInterface;

class Assistant
{
    private const MAX_ITERATIONS = 5;
    private const MAX_TOKENS = 1500;
    private const TEMPERATURE = 0.5;

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly ToolRegistry $toolRegistry,
        private readonly LoggerInterface $logger,
        private readonly string $model,
    ) {
    }

    /**
     * Основной вход. Собирает system prompt, запускает tool use loop,
     * возвращает финальный текст для пользователя.
     */
    public function handle(User $user, string $userMessage, \DateTimeImmutable $now): AssistantResult
    {
        $userTz = new \DateTimeZone($user->getTimezone());
        $systemPrompt = $this->buildSystemPrompt($now->setTimezone($userTz), $userTz);
        $tools = $this->toolRegistry->getAnthropicSchemas();

        $messages = [
            ['role' => 'user', 'content' => $userMessage],
        ];

        $toolsCalled = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $iterations = 0;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $iterations++;

            $response = $this->claude->createMessage(
                systemPrompt: $systemPrompt,
                messages: $messages,
                model: $this->model,
                maxTokens: self::MAX_TOKENS,
                temperature: self::TEMPERATURE,
                tools: $tools,
            );

            $totalInputTokens += $response->inputTokens;
            $totalOutputTokens += $response->outputTokens;

            $contentBlocks = $response->data['content'] ?? [];
            $stopReason = $response->stopReason;

            // Если Claude не просит инструмент — это финальный ответ
            if ($stopReason !== 'tool_use') {
                $text = $this->extractTextFromBlocks($contentBlocks);

                return new AssistantResult(
                    replyText: $text !== '' ? $text : 'Готово.',
                    toolsCalled: $toolsCalled,
                    inputTokens: $totalInputTokens,
                    outputTokens: $totalOutputTokens,
                    iterations: $iterations,
                );
            }

            // Добавляем assistant-сообщение целиком (включая tool_use блоки) в историю.
            // Нормализуем tool_use.input — если Claude вернул [] или null (бывает когда
            // у tool нет обязательных параметров, или когда вызывается без аргументов),
            // при эхо назад API 400-тит с «Input should be a valid dictionary».
            // Пустой массив в PHP сериализуется как [], объект — как {}.
            $messages[] = [
                'role' => 'assistant',
                'content' => $this->normalizeToolUseInputs($contentBlocks),
            ];

            // Исполняем все tool_use блоки
            $toolResults = [];
            foreach ($contentBlocks as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $name = (string) ($block['name'] ?? '');
                $id = (string) ($block['id'] ?? '');
                $input = (array) ($block['input'] ?? []);

                $toolsCalled[] = $name;
                $this->logger->info('Assistant tool_use', [
                    'tool' => $name,
                    'tool_use_id' => $id,
                    'input' => $input,
                ]);

                $result = $this->toolRegistry->execute($name, $user, $input);

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $id,
                    'content' => $result->content,
                    'is_error' => !$result->success,
                ];
            }

            // Отдаём результаты обратно Claude
            $messages[] = [
                'role' => 'user',
                'content' => $toolResults,
            ];
        }

        // Исчерпан лимит итераций
        $this->logger->warning('Assistant iteration limit exceeded', [
            'iterations' => $iterations,
            'tools_called' => $toolsCalled,
        ]);

        return new AssistantResult(
            replyText: 'Слишком долго думал, остановился. Попробуй переформулировать.',
            toolsCalled: $toolsCalled,
            inputTokens: $totalInputTokens,
            outputTokens: $totalOutputTokens,
            iterations: $iterations,
        );
    }

    /**
     * Для всех tool_use блоков гарантирует, что input сериализуется как JSON-объект,
     * а не как массив: Claude может вернуть input=[] (пустой массив) или null,
     * Anthropic API требует dict. Пустой stdClass → {} при json_encode.
     */
    private function normalizeToolUseInputs(array $blocks): array
    {
        foreach ($blocks as &$block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $input = $block['input'] ?? null;
            if (!is_array($input) || $input === [] || array_is_list($input)) {
                $block['input'] = new \stdClass();
            }
        }

        return $blocks;
    }

    private function extractTextFromBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'] ?? '';
            }
        }

        return trim(implode('', $parts));
    }

    private function buildSystemPrompt(\DateTimeImmutable $nowLocal, \DateTimeZone $userTz): string
    {
        $nowStr = $nowLocal->format('Y-m-d H:i (l)');
        $tzName = $userTz->getName();

        return <<<PROMPT
        Ты — персональный AI-ассистент по управлению задачами для пользователя.

        Текущее время пользователя: {$nowStr} ({$tzName})
        Язык общения: русский

        Твоя роль — понимать запросы пользователя на естественном языке и выполнять их через доступные инструменты. Пользователь пишет тебе свободным текстом, ты разбираешься что он хочет.

        ## Основные сценарии

        1. **Пользователь описывает новую задачу** — используй create_task.
           Примеры: «Купить молоко», «Завтра сходить в поликлинику», «Нужно забрать документы в МФЦ до пятницы»

        2. **Пользователь спрашивает про задачи** — используй list_tasks.
           Примеры: «Что у меня на сегодня?», «Какие задачи по работе?», «Покажи выполненное»

        3. **Пользователь сообщает, что сделал задачу** — используй mark_task_done.
           Примеры: «Билеты купил», «Корм коту купил, кстати», «Сделал тренировку»

        4. **Пользователь хочет отложить задачу** — используй snooze_task.
           Примеры: «Отложи тренировку на завтра», «Напомни про письмо через час»

        5. **Просто вопрос или приветствие** — ответь текстом, без использования tools.
           Примеры: «Привет», «Как дела», «Сколько у меня задач?» (последний — лучше через list_tasks, но можно и текстом если уточнение)

        ## Принципы работы

        - **Будь лаконичным.** Короткие дружелюбные ответы, 1-3 предложения. Без длинных объяснений.
        - **Используй tools когда надо.** Если пользователь явно описывает действие — не спрашивай подтверждения, делай.
        - **Задавай уточняющие вопросы только при реальной неоднозначности.** «Какую именно тренировку?» — если mark_task_done вернул несколько совпадений. «Купить молоко где?» — НЕ спрашивай, это избыточно.
        - **Неоднозначность задач по названию.** Если tool вернул список с несколькими совпадениями — покажи их пользователю коротко и спроси какую имел в виду.
        - **Время и даты — в timezone пользователя.** Когда генерируешь ISO-дату для snooze — используй timezone пользователя ({$tzName}). Не UTC.
        - **Приоритеты в интерпретации времени:**
          - «завтра» без времени → 18:00
          - «завтра утром» → 09:00
          - «вечером» → 19:00
          - «через час» / «через 2 часа» → now + указанное
          - «через неделю» → now + 7 дней, то же время

        ## Как работают напоминания

        У бота есть автоматические напоминания трёх типов (работают сами, без участия пользователя):

        1. **Перед дедлайном** — за N минут до deadline. Настраивается через remind_before_deadline_minutes при создании задачи.

        2. **Периодические** — каждые N минут, пока задача не выполнена. Настраивается через reminder_interval_minutes. Используется для задач без дедлайна. Для задач в работе (IN_PROGRESS) эффективный интервал удваивается.

        3. **Пробуждение отложенных** — если пользователь отложил задачу до конкретного времени, бот уведомит когда оно наступит.

        Когда пользователь просит «напоминай мне...» — задача создаётся через create_task, и AI-парсер сам проставит нужные поля. НЕ ГОВОРИ что не умеешь автоматически напоминать — умеешь. НЕ ПРЕДЛАГАЙ пользователю «скажи мне ещё раз» как ручной workaround — это ложь, всё автоматизировано.

        Когда пользователь получит напоминание, в нём есть кнопки:
        - «✅ Сделал» — задача закрывается
        - «⏸ Отложить на час» — задача отложится, напоминание повторится после
        - «🚀 Беру в работу» — статус IN_PROGRESS

        **Не путай создание периодических напоминаний со snooze.** Когда пользователь просит создать задачу с периодическими напоминаниями — задача становится активной **сразу**, первое напоминание придёт через указанный интервал после создания. Не предлагай «отложить задачу» — это другая операция (snooze_task), она не нужна при создании задачи.

        Правильный ответ на «создай задачу и напоминай каждые 2 часа»:
        «Готово! Создал задачу X. Первое напоминание — через 2 часа, в HH:MM.»

        Если сейчас вечер и первое напоминание попадёт в тихие часы — можно упомянуть: «…но если попадёт в тихие часы (22:00–08:00), первое напоминание придёт утром».

        Если пользователь просит более частый интервал чем 60 минут — парсер всё равно поставит минимум 60. В ответе честно скажи: «поставил минимум час, чаще напоминать не буду — будет раздражать».

        ## Формат ответа пользователю

        После выполнения tool'а — сформулируй короткий ответ по-человечески. Не нужно повторять всё что вернул tool, но подтверди что сделал:

        ❌ Плохо: «Я использовал create_task. Вот параметры: title=..., deadline=..., priority=...»
        ✅ Хорошо: «✅ Создал: Купить молоко. Напомню перед выходом.»

        ❌ Плохо: «Выполнил mark_task_done с task_query='корм'. Результат: Задача помечена.»
        ✅ Хорошо: «Отметил ✅. Что-то ещё?»

        Не добавляй технические детали, UUID, имена tools в ответы — это для внутреннего использования.

        ## Эмодзи

        Можно использовать в меру: ✅ для подтверждения, 📝 для создания, ⏰ для времени. Не перебарщивай.
        PROMPT;
    }
}
