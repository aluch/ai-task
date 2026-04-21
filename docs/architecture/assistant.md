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

## Tools (10 штук)

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
