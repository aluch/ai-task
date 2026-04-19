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

## Tools (MVP)

Все в `App\AI\Tool\`. Каждый — отдельный класс.

### `create_task`

Создать новую задачу. Использует `TaskParser` для извлечения структуры (title/deadline/priority/contexts) из сырого текста. Source = `AI_PARSED`. Input: `{raw_text: string}`.

### `list_tasks`

Показать задачи. Input: `{status_filter?: "active"|"done"|"snoozed"|"all", query?: string, limit?: int}`. Default `status_filter=active`. Query — ILIKE в title. Возвращает компактный список с `[id:uuid]` — Claude использует эти UUID для последующих mark_task_done/snooze_task.

### `mark_task_done`

Пометить задачу выполненной. Input: `{task_id?: string, task_query?: string}`. Если task_id — прямой lookup. Если task_query — поиск по title. >1 совпадение → `success=false` со списком, Claude спрашивает пользователя. После успеха пишет в result сколько задач разблокировалось.

### `snooze_task`

Отложить задачу. Input: `{task_id?: string, task_query?: string, until_iso: string}`. `until_iso` в timezone пользователя (не UTC — Claude знает зону из system prompt). Проверка что в будущем.

## System prompt

Центр поведения. Структура:

```
Ты — персональный AI-ассистент...
Текущее время пользователя: {now_in_user_tz} ({user_tz})
Язык общения: русский

## Основные сценарии
1. Новая задача → create_task
2. Спрашивает про задачи → list_tasks
3. Сделал задачу → mark_task_done
4. Отложить → snooze_task
5. Привет/вопрос → просто текст, без tools

## Принципы работы
- Лаконичность (1-3 предложения)
- Используй tools без лишних вопросов
- Уточняй только при реальной неоднозначности
- Времена в timezone пользователя (для snooze ISO)
- Интерпретация времени: «завтра» без указания → 18:00, «завтра утром» → 09:00

## Формат ответа
Плохо: «Я использовал create_task с параметрами...»
Хорошо: «✅ Создал: Купить молоко.»

Не показывай UUID, имена tools, технические детали.
```

## Retry и обработка ошибок

- **Исключение внутри tool'а** → `ToolRegistry.execute()` ловит, возвращает `ToolResult(success=false)` с сообщением об ошибке → Claude решает что сказать пользователю (обычно «не получилось, попробуй ещё раз»).
- **Сетевые/5xx ошибки Claude** → `ClaudeTransientException` пробрасывается из `Assistant::handle()` → `AssistantHandler` ловит и отвечает «⚠️ Что-то пошло не так».
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

## Известные ограничения (MVP)

Будет исправлено в следующих шагах:

- **Нет истории диалога.** Каждое сообщение пользователя — новая сессия. Ассистент не помнит «купил молоко» → «а ещё хлеб» (второе создаст отдельную задачу без контекста).
- **Нет Reply-механики.** Нельзя ответить реплаем на сообщение ассистента чтобы уточнить.
- **Нет tools для редактирования.** Можно создать/закрыть/отложить, но нельзя «измени дедлайн задачи X на завтра». Добавится как `update_task` tool.
- **Нет tool для /free-подбора.** Пользователь всё ещё должен явно использовать команду `/free`.
- **Нет tool для блокировок.** `/block` и `/unblock` — только через команды.

## Переменные окружения

| Переменная | Описание | Default |
|---|---|---|
| `ANTHROPIC_MODEL_ASSISTANT` | Модель ассистента | `claude-sonnet-4-6` |
