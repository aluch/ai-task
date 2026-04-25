# AI-ассистент (tool calling)

Документ описывает архитектуру AI-ассистента, который заменяет `FreeTextHandler` для свободного текста. Ассистент понимает запросы на естественном языке и выполняет действия через tool calling.

## Концепция

**До (FreeTextHandler):** свободный текст → `TaskParser` → создание задачи. Всегда одна операция — извлечение структуры.

**После (Assistant):** свободный текст → `Assistant` → Claude с tools → Claude сам выбирает что делать (создать, перечислить, пометить выполненной, отложить) → исполнение через существующие сервисы → ответ пользователю.

Команды (`/list`, `/done`, `/free` и т.д.) остаются как быстрый путь. Ассистент — для свободного текста.

## Архитектура

```
AssistantHandler (Telegram)
        │
        │ handle(user, text, now)
        ▼
   Assistant ─── system prompt + messages + tools ──▶ ClaudeClient
        │                                                │
        │    stop_reason == "tool_use"?                   │
        │    │                                           │
        │    ├─ Yes → ToolRegistry.execute() ──┐          │
        │    │                                 │          │
        │    │         (CreateTaskTool,        │          │
        │    │          ListTasksTool,         │          │
        │    │          MarkTaskDoneTool,      │          │
        │    │          SnoozeTaskTool)        │          │
        │    │                                 ▼          │
        │    │        tool_result ────────────▶┐          │
        │    │                                 └──────────┘
        │    │    (повтор до stop_reason == "end_turn")
        │    │
        │    └─ No (end_turn) → финальный текст
        │
        ▼
   AssistantResult (replyText, toolsCalled, tokens, iterations)
```

## Компоненты

### `App\AI\Assistant`

Центральный класс. Метод `handle(User $user, string $userMessage, \DateTimeImmutable $now): AssistantResult`:

1. Собирает system prompt (роль, текущее время, timezone).
2. Запрашивает `ToolRegistry` схемы tools и передаёт в Claude API.
3. Входит в tool use loop (max 5 итераций):
   - Вызывает `ClaudeClient::createMessage()` с tools.
   - Если `stop_reason == "tool_use"` — исполняет все `tool_use` блоки через `ToolRegistry`, добавляет результаты в messages, повторяет.
   - Иначе извлекает text из content blocks — это финальный ответ.
4. Возвращает `AssistantResult` (текст + метаданные).

**Параметры:**
- Модель: `claude-sonnet-4-6` (env `ANTHROPIC_MODEL_ASSISTANT`).
- Temperature: 0.5 (естественное общение, без хаоса).
- Max tokens: 1500 (ответы длиннее чем у парсера).
- Max iterations: 5 (защита от бесконечного цикла — multi-step вроде поиск → действие умещается в 2).

### `App\AI\Tool\AssistantTool` (интерфейс)

```php
public function getName(): string;                    // snake_case имя для Claude
public function getDescription(): string;             // описание для Claude
public function getInputSchema(): array;              // JSON Schema
public function execute(User $user, array $input): ToolResult;
```

### `App\AI\Tool\ToolRegistry`

Авторегистрация через `_instanceof` + `tagged_iterator` в `services.yaml`: любой класс, реализующий `AssistantTool`, автоматически попадает в registry. Метод `execute()` ловит любые исключения tool'а и превращает их в `ToolResult(success=false)` — Claude получает понятный error-message вместо 500 у пользователя.

### `App\AI\Tool\ToolResult`

DTO: `success`, `content` (идёт обратно в Claude), `metadata` (для логов, не передаётся Claude).

### `App\AI\DTO\AssistantResult`

DTO результата: `replyText`, `toolsCalled` (имена tools в порядке вызова), `inputTokens`, `outputTokens`, `iterations`.

### `App\Telegram\Handler\AssistantHandler`

Регистрируется на fallback для текста без `/`. Отправляет «🤔 Думаю...», вызывает Assistant, редактирует сообщение на ответ. Исключения ловит и редактирует на «⚠️ Что-то пошло не так».

Дополнительно:
- Детектирует Reply на бота: если `$message->reply_to_message->from->is_bot === true`, передаёт `msg_id` ответа в `Assistant::handle`.
- После успешного прохождения Ассистента сохраняет оба сообщения (user + assistant) в `ConversationHistoryStore`. `telegramMsgId` ассистентского сообщения — это `message_id` от `sendMessage('🤔 Думаю...')`, т.к. `editMessageText` редактирует его inplace, id не меняется.

### `App\Telegram\Handler\ResetHandler`

`/reset` — вызывает `ConversationHistoryStore::clear($user)` и отвечает «🆕 Диалог сброшен.».

## Память о диалоге (Redis)

Ассистент помнит последние **10 сообщений** диалога, TTL **30 минут** с последней активности. Хранилище — `ConversationHistoryStore` на Redis. Схема:

- **Ключ**: `conversation:<user_uuid>`
- **Значение** (JSON):
  ```json
  {
    "user_id": "019d...",
    "messages": [
      {"role":"user", "text":"...", "telegram_msg_id":123, "at":"...", "reply_to_msg_id":null, "tools_called":[]},
      {"role":"assistant", "text":"...", "telegram_msg_id":124, "at":"...", "tools_called":["create_task"]}
    ],
    "last_activity_at": "..."
  }
  ```
- **Sliding window**: при `append` если в истории уже 10 — выпадает самое старое.
- **TTL**: 30 минут. Каждый `append` делает `SETEX ... 1800`. Молчит 30 минут — ключ исчезает автоматически.

### Передача в Claude API

`Assistant::handle` перед текущим user-сообщением раскладывает историю как `messages[]`:
```
[
  {role: "user", content: "<прошлое сообщение>"},
  {role: "assistant", content: "<прошлая реплика бота>"},
  ...
  {role: "user", content: "<context>Текущее время: ...</context>\n\n<текущий текст>"}
]
```

`tool_use`/`tool_result` в историю **не сохраняются** — только финальные тексты. Это уменьшает размер и упрощает восприятие моделью.

### Reply-механика

Если пользователь сделал Telegram Reply на сообщение бота:
1. `AssistantHandler` определяет `$reply_to_message->from->is_bot === true` и извлекает `message_id`.
2. Передаёт в `Assistant::handle` как `$replyToTelegramMsgId`.
3. Ассистент ищет в истории сообщение с `role='assistant'` и совпадающим `telegramMsgId`.
4. Если нашёл — добавляет в текущий user-блок:
   ```
   <reply_context>
   Пользователь ответил на твоё предыдущее сообщение:
   «<цитата реплики бота>»
   </reply_context>

   <сам текст пользователя>
   ```
5. Если не нашёл (TTL истёк, окно сдвинулось) — логирует warning, работает как обычное сообщение.

### Логирование

- `Assistant input` — `user_id`, `history_size`, `reply_context: bool` перед вызовом Claude.
- `Assistant reply target not found in history` — если Reply указал на исчезнувшее сообщение.
- `Assistant history reset` — при `/reset` с `size_before`.

## Подтверждения операций

Рискованные и массовые операции исполняются в два шага: tool сохраняет описание операции в Redis (`PendingActionStore`) и возвращает в Claude префикс `PENDING_CONFIRMATION:<type>:<id>`. Ассистент формулирует понятный текст для пользователя и вставляет маркер `[CONFIRM:<id>]` (8 hex). `AssistantHandler` парсит маркер, заменяет его на inline-кнопки `✅ Подтверждаю / ❌ Отмена` (callback `confirm:<id>:yes|no`).

**Tools, требующие подтверждения:**

| Tool | actionType | Когда |
|---|---|---|
| `create_task` (`tasks` ≥ 2) | `create_tasks_batch` | Массовое создание задач за один вызов |
| `cancel_task` | `cancel_task` | Отмена задачи (статус CANCELLED) |
| `bulk_operation` (`mark_done`/`snooze`/`set_priority`/`cancel`) | `bulk_<op>` | Массовые действия над несколькими задачами |

**Что НЕ требует подтверждения:** одиночное создание, mark_done одной, snooze одной, update полей, add_reminder, block/unblock, list/search/suggest/deps.

**Хранилище** (`PendingActionStore`): Redis, ключ `pending_action:<short_id>`, TTL 5 минут. Дополнительный индекс `pending_action_user:<uuid>` → последний `short_id` (для текстового подтверждения «да»).

**Исполнение** (`ConfirmationExecutor`): единая точка для callback и текста. На «yes» — `consume()` (атомарно), вызов `execute(User, PendingAction)` → строка результата. На «no» — `consume()` без исполнения, ответ «👌 Отменено».

**Текстовое подтверждение.** В `AssistantHandler::__invoke` проверяется: если у user'а есть свежий `latestForUser` и текст ∈ {«да», «ок», «подтверждаю», «давай», «yes», «+», «ага», «угу», «согласен»} (case-insensitive) — обработать как кнопку yes. Аналогично для «нет», «отмена», «no» и т.д. Если текст не совпал ни с чем — обычный flow Ассистента, pending остаётся в Redis до TTL.

**Истечение TTL.** После 5 минут ключ исчезает из Redis. При нажатии кнопки или текстовом «да» `consume()` вернёт null → ответ «⏰ Действие устарело — попроси заново».

## Tools (13 штук)

Все в `App\AI\Tool\`, автоконфигурируются тегом `app.assistant_tool`. Общий
helper `App\AI\Tool\Support\TaskLookup` — поиск по id или fuzzy query,
возвращает Task или ToolResult::error с причиной.

### `create_task`

Создать новую задачу. Использует `TaskParser` для извлечения структуры.
Input: `{raw_text: string, force?: bool}`.

**Защита от дубликатов**: перед созданием извлекает 2-3 ключевых корня из
title (отсекая короткие слова и типовые глаголы `купить`/`сделать`/...),
ищет активные задачи пользователя по ILIKE с порогом «совпало ≥ половины
корней». Если кандидаты найдены — `success=false` со списком. Claude
должен переспросить пользователя либо вызвать update_task. Для обхода —
`force=true` (явное подтверждение «именно новая»).

### `list_tasks`

Показать задачи. Input: `{status_filter?: "active"|"done"|"snoozed"|"all", query?: string, limit?: int}`. Default `status_filter=active`.

### `search_tasks_by_title`

Найти по части title/description. Морфо-стемминг (обрезка 2 последних
символов для слов >3). Input: `{query: string}`. До 10 результатов со
статусами и дедлайнами.

### `update_task`

Обновить поля существующей задачи. Input: `{task_id?, task_query?, updates: {title?, description?, deadline_iso?, priority?, estimated_minutes?, context_codes?, remind_before_deadline_minutes?, reminder_interval_minutes?}}`.

`context_codes` — полный желаемый список (заменяет существующие).
`deadline_iso=null` или `""` — убрать дедлайн. При изменении дедлайна
или `remind_before_deadline_minutes` автоматически сбрасывается
`deadline_reminder_sent_at` чтобы новое напоминание сработало.

### `mark_task_done`

Пометить выполненной. Input: `{task_id?, task_query?}`. >1 совпадение →
`success=false` со списком, Claude спрашивает пользователя.

### `snooze_task`

Отложить. Input: `{task_id?, task_query?, until_iso: string}`.

### `add_reminder_to_task`

Добавить/изменить напоминание. Input: `{task_id_or_query, remind_before_deadline_minutes?, reminder_interval_minutes?}`. Нужен хотя бы один
из двух. Для `remind_before_deadline_minutes` требует наличия `deadline`
у задачи — иначе error с подсказкой. Для `reminder_interval_minutes`
клампит к минимуму 60. Сбрасывает `deadline_reminder_sent_at` и
`last_reminded_at`.

### `block_task` / `unblock_task`

Связать / расцепить задачи. Input обоих: `{blocked_task_id_or_query, blocker_task_id_or_query}`. Проверка циклов через `DependencyValidator`.

### `suggest_tasks`

Подобрать задачи под время + контекст. Вызывает существующий
`TaskAdvisor::suggest`. Input: `{available_minutes: int, context_description?: string}`. Возвращает список 2-5 предложений с reasoning.
Без inline-кнопок — только текст (кнопочный UX доступен через команду `/free`).

### `add_single_reminder`

Установить одноразовое напоминание на точный момент (Тип Г). Input:
`{task_id_or_query: string, at_iso: string (ISO 8601 с TZ пользователя), respect_quiet_hours?: bool default false}`.

Отличается от `add_reminder_to_task`: там напоминание ПРИВЯЗАНО к дедлайну
или периодическому расписанию. Здесь — просто «в HH:MM пришли уведомление»,
задача при этом **остаётся активной** (в отличие от `snooze_task`).

По умолчанию `respect_quiet_hours=false` — пользователь сам выбрал время,
ночь не помеха. Перезаписывает предыдущий single-таймер если был.

## System prompt

Центр поведения. Основные секции:

- **Что ты умеешь** — явный список 10 tools с описанием. Избавляет модель от
  фантазий «я не могу напоминать», «удали эту задачу» (удаление не реализовано).
- **Чего НЕ умеешь** — честно: удаление, геолокация, память между сообщениями,
  календарь.
- **Основные сценарии** — краткий mapping «намерение пользователя → tool».
  Коррекция задачи (update_task) отдельно подчёркнута, чтобы не путалась с
  create_task.
- **Принципы** — лаконичность, timezone-локальные даты, дефолты интерпретации
  времени, обработка неоднозначности (не выбирать самому кроме очевидных
  случаев), защита от дубликатов.
- **Как работают напоминания** — три типа (А/Б/В), кнопки, минимум 60 минут
  для интервалов, разграничение periodic vs snooze.
- **Формат ответа: «что у меня есть»** — выбор формата по количеству:
  ≤5 простой список, 6-15 группировка по важности, >15 top-10 + «ещё N».
  Консистентность при повторном запросе.
- **Формат ответа в целом** — без технических деталей (UUID, имена tools),
  короткие подтверждения.

## Retry и обработка ошибок

- **Исключение внутри tool'а** → `ToolRegistry.execute()` ловит, возвращает `ToolResult(success=false)` с сообщением об ошибке → Claude решает что сказать пользователю (обычно «не получилось, попробуй ещё раз»).
- **Rate-limit (429) и transient (5xx) от Anthropic** → `Assistant::createMessageWithRetry` ловит `ClaudeRateLimitException` / `ClaudeTransientException` и делает до 3 попыток. Для 429 уважает `retry_after` из ответа, для 5xx — backoff 1s/2s/3s. После исчерпания попыток — пробрасывает наружу, `AssistantHandler` отвечает «⚠️ Что-то пошло не так».
- **Достигнут max_iterations** → возвращается текст «Слишком долго думал, остановись. Попробуй переформулировать» + warning в логи.

## Как добавить новый tool

1. Создать класс в `App\AI\Tool\`, реализовать `AssistantTool`.
2. Всё. Автоконфиг `_instanceof` тегирует его как `app.assistant_tool`, `ToolRegistry` подхватывает через tagged_iterator.
3. Переписывать handler'ы или регистрацию не надо — Claude увидит новый tool в системном промпте (через `getAnthropicSchemas()`) и начнёт его использовать когда сочтёт уместным.
4. Уточнения в `getDescription()` критичны — по нему Claude решает когда вызывать. Описывай сценарии как в существующих tools.

## Логирование

- `Assistant tool_use` (info) на каждый вызов tool'а с именем и input.
- `Assistant result` (info) в `AssistantHandler` — tokens, iterations, tools_called.
- `Tool execution failed` (error) в `ToolRegistry` при исключении.
- `Assistant iteration limit exceeded` (warning) при достижении 5 итераций.
- `Assistant requested unknown tool` (warning) — Claude выдумал tool которого нет (редкое поведение модели).

## Старый FreeTextHandler

Код `FreeTextHandler` оставлен на месте, но **не зарегистрирован** в `HandlerRegistry` — fallback теперь идёт в `AssistantHandler`. Если ассистент начнёт сбоить — откат через одну строку в `HandlerRegistry::register()`. После стабилизации можно удалить.

## Защита от дубликатов

Встроена в `CreateTaskTool`. Алгоритм (`findDuplicates` + `extractKeywords`):

1. Из title извлекаются 2-3 «значимых корня»: слова длиной >3, не из
   стоп-листа (`купить`, `сделать`, `позвонить`, `про`, `для` и т.д.),
   обрезанные на 2 символа (морфо-стеммер).
2. Ищутся активные задачи (PENDING/IN_PROGRESS/SNOOZED) пользователя с
   ILIKE совпадением по любому корню.
3. Кандидаты фильтруются по порогу: у многословных title нужно совпадение
   минимум половины корней (округлено вверх). Для одного корня — просто
   совпадение.
4. Если список не пуст → `ToolResult::error` со списком кандидатов.
   Assistant должен спросить пользователя: создать форсом (`force=true`)
   или обновить существующую через update_task.

Пример: создав «Купить молоко», вторая попытка «Купить молоко» или
«Купи молока» отклонится (корни «молок» совпал). А «Купить билеты»
пройдёт (другой корень).

## Smoke-сценарии для Ассистента

6 сценариев в `App\Smoke\ScenarioRunner`, все через `make smoke-all`:

| Сценарий | Что проверяет |
|---|---|
| `assistant-basic-flow` | create → list → done → list=empty |
| `assistant-update-task` | update меняет дедлайн и сбрасывает `deadline_reminder_sent_at`, не создаёт вторую задачу |
| `assistant-duplicate-prevention` | повторное «купить молоко» не создаёт второй задачи с тем же корнем |
| `assistant-mark-done-ambiguous` | «звонок сделал» при двух «звонк»-задачах — ни одну не закрывает, спрашивает уточнение |
| `assistant-block-tasks` | естественная формулировка связи создаёт зависимость blocked→blocker |
| `assistant-suggest-tasks` | вызывается suggest_tasks, в reply упомянута хотя бы одна предложенная задача |

Между assistant-сценариями `smoke:all` делает `sleep(8)` — иначе
упирается в 30k TPM rate-limit Anthropic.

## Известные ограничения (MVP)

- **Нет истории диалога.** Каждое сообщение — новая сессия. Ассистент
  не помнит «купил молоко» → «а ещё хлеб» (второе создаст отдельную задачу
  без контекста).
- **Нет Reply-механики.** Нельзя ответить реплаем на сообщение ассистента
  чтобы уточнить.

## Переменные окружения

| Переменная | Описание | Default |
|---|---|---|
| `ANTHROPIC_MODEL_ASSISTANT` | Модель ассистента | `claude-sonnet-4-6` |
