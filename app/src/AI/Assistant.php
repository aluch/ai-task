<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\AssistantResult;
use App\AI\DTO\HistoryMessage;
use App\AI\Exception\ClaudeRateLimitException;
use App\AI\Exception\ClaudeTransientException;
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
        private readonly ConversationHistoryStore $history,
        private readonly LoggerInterface $logger,
        private readonly string $model,
    ) {
    }

    /**
     * Основной вход. Собирает system prompt, подтягивает историю,
     * запускает tool use loop, возвращает финальный текст для пользователя.
     *
     * $replyToTelegramMsgId — если пользователь сделал Reply на сообщение
     * бота, передаём сюда msg_id ответа. Если этот msg_id найдётся в
     * истории Ассистента, в user-message будет добавлен блок <reply_context>.
     * Если не найдётся (выпал из окна) — просто игнорируем, не падаем.
     */
    public function handle(
        User $user,
        string $userMessage,
        \DateTimeImmutable $now,
        ?int $replyToTelegramMsgId = null,
    ): AssistantResult {
        $userTz = new \DateTimeZone($user->getTimezone());
        // Systom prompt — только стабильный контент (инструкции + timezone).
        // Текущее время идёт в user message, чтобы не инвалидировать
        // prompt cache каждую минуту (nowStr — самый частый silent invalidator).
        $systemPrompt = $this->buildSystemPrompt($userTz);
        $tools = $this->toolRegistry->getAnthropicSchemas();

        $nowStr = $now->setTimezone($userTz)->format('Y-m-d H:i (l)');

        // 1. История (до 10 сообщений, TTL 30 мин).
        $historyMessages = $this->history->get($user);
        $messages = [];
        foreach ($historyMessages as $h) {
            $messages[] = ['role' => $h->role, 'content' => $h->text];
        }

        // 2. Reply-context — если текущее сообщение Reply на бота и бот
        //    ещё в истории, подмешиваем его текст в новое user-сообщение.
        $replyContext = null;
        if ($replyToTelegramMsgId !== null) {
            foreach ($historyMessages as $h) {
                if ($h->role === 'assistant' && $h->telegramMsgId === $replyToTelegramMsgId) {
                    $replyContext = $h->text;
                    break;
                }
            }
            if ($replyContext === null) {
                $this->logger->info('Assistant reply target not found in history', [
                    'reply_to_msg_id' => $replyToTelegramMsgId,
                    'history_size' => count($historyMessages),
                ]);
            }
        }

        // 3. Текущее сообщение пользователя — с <context> и опциональным
        //    <reply_context>.
        $userBlock = "<context>Текущее время: {$nowStr}</context>\n\n";
        if ($replyContext !== null) {
            $userBlock .= "<reply_context>\nПользователь ответил на твоё предыдущее сообщение:\n«{$replyContext}»\n</reply_context>\n\n";
        }
        $userBlock .= $userMessage;
        $messages[] = ['role' => 'user', 'content' => $userBlock];

        $this->logger->info('Assistant input', [
            'user_id' => $user->getId()->toRfc4122(),
            'history_size' => count($historyMessages),
            'reply_context' => $replyContext !== null,
        ]);

        $toolsCalled = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCacheReadTokens = 0;
        $totalCacheCreationTokens = 0;
        $iterations = 0;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $iterations++;

            $response = $this->createMessageWithRetry(
                $systemPrompt,
                $messages,
                $tools,
            );

            $totalCacheReadTokens += $response->cacheReadInputTokens;
            $totalCacheCreationTokens += $response->cacheCreationInputTokens;

            $totalInputTokens += $response->inputTokens;
            $totalOutputTokens += $response->outputTokens;

            $contentBlocks = $response->data['content'] ?? [];
            $stopReason = $response->stopReason;

            // Если Claude не просит инструмент — это финальный ответ
            if ($stopReason !== 'tool_use') {
                $text = $this->extractTextFromBlocks($contentBlocks);

                $this->logger->info('Assistant completed', [
                    'iterations' => $iterations,
                    'tools_called' => $toolsCalled,
                    'input_tokens' => $totalInputTokens,
                    'cache_read_tokens' => $totalCacheReadTokens,
                    'cache_creation_tokens' => $totalCacheCreationTokens,
                    'output_tokens' => $totalOutputTokens,
                ]);

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
     * Создать message с простым retry-on-transient. Претензии 5xx/rate-limit
     * от Anthropic прилетают чаще в пиковые часы — одного перезапуска обычно
     * хватает. Client-ошибки (4xx) не повторяем.
     */
    private function createMessageWithRetry(
        string $systemPrompt,
        array $messages,
        array $tools,
    ): \App\AI\DTO\ClaudeResponse {
        $maxAttempts = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->claude->createMessage(
                    systemPrompt: $systemPrompt,
                    messages: $messages,
                    model: $this->model,
                    maxTokens: self::MAX_TOKENS,
                    temperature: self::TEMPERATURE,
                    tools: $tools,
                    cacheSystem: true,
                    cacheTools: true,
                );
            } catch (ClaudeRateLimitException $e) {
                $lastException = $e;
                $wait = $e->retryAfter ?? 5;
                $this->logger->warning('Assistant rate limited, waiting {wait}s', [
                    'wait' => $wait,
                    'attempt' => $attempt,
                ]);
                if ($attempt < $maxAttempts) {
                    sleep($wait);
                }
            } catch (ClaudeTransientException $e) {
                $lastException = $e;
                $wait = $attempt; // 1s, 2s, 3s
                $this->logger->warning('Assistant transient error, retrying', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'wait' => $wait,
                ]);
                if ($attempt < $maxAttempts) {
                    sleep($wait);
                }
            }
        }

        throw $lastException;
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

    private function buildSystemPrompt(\DateTimeZone $userTz): string
    {
        $tzName = $userTz->getName();

        return <<<PROMPT
        Ты — персональный AI-ассистент по управлению задачами для пользователя.

        Часовой пояс пользователя: {$tzName}
        Язык общения: русский
        Текущее время пользователя передаётся в первом user-сообщении в теге <context>.

        Твоя роль — понимать запросы пользователя на естественном языке и выполнять их через доступные инструменты. Пользователь пишет тебе свободным текстом, ты разбираешься что он хочет.

        ## Память о диалоге

        У тебя есть доступ к последним 10 сообщениям этого диалога (окно 30 минут активной беседы). Они приходят в messages выше текущего. Используй их когда:

        - Пользователь говорит «её», «эту», «ту задачу», «отложи на завтра» без названия — посмотри в историю, какая задача обсуждалась последней. Действуй с ней.
        - Пользователь даёт ответ на твой предыдущий уточняющий вопрос («банк» после «какой звонок — в страховую или в банк?») — можешь теперь продолжить.
        - Пользователь делает Reply на твоё сообщение — в новом user-сообщении будет блок `<reply_context>` с цитатой твоей реплики. Используй её как контекст.

        Если в истории нет информации для понимания — в этом же ответе предложи уточнение (см. блок про уточняющие вопросы ниже).

        Если обсуждение кардинально сменило тему (несколько сообщений про задачи, потом внезапно «какая погода?») — игнорируй старую историю, работай с новым контекстом.

        ## Когда можно задавать уточняющие вопросы

        Поскольку память есть, вопрос-уточнение теперь работает: пользователь ответит, ты увидишь его реплику в истории и продолжишь. Но всё равно действуй, а не переспрашивай, когда можешь:

        - ✅ Хорошо: «Нашёл две задачи со словом "звонок": в страховую и в банк. Какую сделал?» — пользователь напишет «банк», и ты поймёшь.
        - ❌ Плохо: «Купить молоко?» — излишне, просто создавай.

        Правило: задавай вопрос если без ответа пользователя ты физически не можешь сделать правильный выбор. Не задавай «для вежливости» и не подтверждай очевидное.

        При нескольких найденных задачах: если одна явно релевантнее (по точности совпадения, по статусу — активная важнее завершённой) — действуй с ней, упомяни что сделал. Если действительно ничья — задай короткий вопрос с перечислением вариантов.

        При дубликатах: `create_task` сам решает — точный дубликат не создаёт (возвращает `DUPLICATE_SKIPPED`), похожий создаёт. Просто передай результат пользователю.

        ## Что ты умеешь (доступные инструменты)

        - **Создать задачу** (create_task) — из свободного текста. Защита от дубликатов встроена: точный дубликат не создаётся (success=true, «такая уже есть»), похожая — создаётся и в ответе перечисляются похожие. НЕ переспрашивай пользователя — просто передай результат.
        - **Показать задачи** (list_tasks) — с фильтрами по статусу (active/done/snoozed/all).
        - **Найти задачу** (search_tasks_by_title) — по части названия или описания, с fuzzy-стеммингом.
        - **Обновить задачу** (update_task) — изменить любое поле: title, дедлайн, priority, контексты, напоминания.
        - **Пометить выполненной** (mark_task_done).
        - **Отложить задачу** (snooze_task) — до конкретного момента. В timezone пользователя.
        - **Добавить напоминание** (add_reminder_to_task) — remind_before_deadline_minutes или reminder_interval_minutes.
        - **Связать задачи** (block_task) — одна блокирует другую (циклы не допускаются).
        - **Разблокировать** (unblock_task).
        - **Подобрать задачи** (suggest_tasks) — когда пользователь спрашивает «что мне сделать?» с контекстом и временем.
        - **Одноразовое напоминание на время** (add_single_reminder) — «напомни в HH:MM», «через 20 минут пингани».

        Чего НЕ умеешь (и честно говори об этом):
        - Удалять задачи (только отменять через пометку done или cancel — в БД остаются).
        - Видеть геолокацию пользователя (только то что он напишет текстом).
        - Помнить больше чем последние 10 сообщений или беседу старше 30 минут (окно истории ограничено). Если пользователь написал `/reset` — история сброшена, это свежий старт.
        - Создавать события в календаре пользователя.

        ## Основные сценарии

        1. **Новая задача** — create_task с raw_text = полный текст пользователя.
        2. **Коррекция задачи** — update_task. «Перенеси стрижку на субботу» — это update, НЕ create. Сначала найди задачу (search_tasks_by_title если неочевидно), потом update.
        3. **Список задач** — list_tasks. Простое «что есть».
        4. **Отметка выполнено** — mark_task_done.
        5. **Отложить** — snooze_task до конкретного момента.
        6. **Что мне делать?** — suggest_tasks с available_minutes и context_description.
        7. **Связать** — block_task / unblock_task.
        8. **Приветствие / вопрос** — ответ текстом без tools.

        ## Принципы работы

        - **Будь лаконичным.** Короткие дружелюбные ответы, 1-3 предложения. Без длинных объяснений.
        - **Используй tools когда надо.** Если пользователь явно описывает действие — не спрашивай подтверждения, делай.
        - **Обработка неоднозначности — см. блок «Когда можно задавать уточняющие вопросы» выше.** Кратко: при одной релевантной — действуй; при ничьей можно задать короткий вопрос (память есть, ответ увидишь). Не спрашивай «для вежливости».
        - **Защита от дубликатов — автоматическая.** `create_task` сам решает: идентичный title → не создаёт; похожий (отличается деталью) → создаёт + упоминает похожие. Просто передай пользователю результат.
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

        ### Какой tool для «напомни» использовать

        - «Напомни про X в 18:00» / «В 6:50 напомни про компостер» / «Через 20 минут пингани» → **add_single_reminder** (одноразовое на конкретный момент, задача остаётся активной).
        - «Напомни за час до дедлайна» / «Предупреди заранее» → **add_reminder_to_task** с `remind_before_deadline_minutes`. Требует дедлайн у задачи.
        - «Напоминай каждые 2 часа» / «Пинай регулярно» → **add_reminder_to_task** с `reminder_interval_minutes`.
        - «Отложи до 18:00» / «Спрячь до завтра» → **snooze_task** (задача исчезает из списков до времени X).

        Не путай add_single_reminder и snooze_task: первый — «напомнить и оставить активной», второй — «скрыть до момента Y». Если формулировка неоднозначна — выбирай add_single_reminder (менее инвазивное, задача остаётся видимой в списках) и упомяни что именно сделал. При желании пользователь переформулирует следующим сообщением.

        При add_single_reminder по умолчанию quiet hours игнорируются (пользователь сам выбрал момент). Если пользователь явно просит «если не поздно», передай respect_quiet_hours=true.

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

        ### Формат ответа на «что у меня есть»

        Выбирай формат по количеству активных задач (pending + in_progress):

        - **≤5** — простой маркированный список, все задачи. Без группировок.
        - **6-15** — сгруппируй по важности: «⚠ Просроченные», «📅 Сегодня / Завтра», «▶ В работе», «Остальные». В «Остальных» показывай ВСЕ, не обобщай «и ещё N».
        - **>15** — первые ~10 сгруппированно (как выше), внизу одна строка «+ ещё N задач — /list».

        Консистентность: если пользователь спросил дважды подряд «что у меня есть» — ответь в одном и том же формате, не переключай произвольно.

        ## Эмодзи

        Можно использовать в меру: ✅ для подтверждения, 📝 для создания, ⏰ для времени. Не перебарщивай.
        PROMPT;
    }
}
