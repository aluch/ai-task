# AI-интеграция

Документ описывает архитектуру AI-слоя AI Task Agent: Claude API клиент, парсер задач, retry-политику и план расширения.

## Архитектура

```
Telegram FreeTextHandler
        │
        │ parse(text, user, now)
        ▼
   TaskParser ──── system prompt + user message ──▶ ClaudeClient
        │                                              │
        │ ParsedTaskDTO                                │ ClaudeResponse
        │                                              │
        ▼                                              ▼
   Task (Entity)                              Anthropic Messages API
```

### `App\AI\ClaudeClient`

Обёртка вокруг Symfony HttpClient для Anthropic Messages API.

- **API URL**: `https://api.anthropic.com/v1/messages`
- **API version**: `2023-06-01`
- **Default model**: `claude-opus-4-6` (переопределяется per-call)
- **Timeout**: 60 секунд

Принимает `systemPrompt`, `messages`, `model`, `maxTokens`, `temperature`, `tools`, `cacheSystem`, `cacheTools`. Возвращает `ClaudeResponse` (DTO: `text`, `stopReason`, `inputTokens`, `outputTokens`, `cacheCreationInputTokens`, `cacheReadInputTokens`, `data`).

**Логирование**: каждый запрос логируется на уровне `info` — модель, input/output tokens, cache_read_tokens, cache_creation_tokens, elapsed, stop_reason. Полезно для отладки и оценки стоимости (см. секцию «Prompt caching» ниже).

**Классификация ошибок**:
| HTTP code | Exception | Retry? |
|---|---|---|
| 4xx | `ClaudeClientException` | Нет — ошибка в запросе |
| 429 | `ClaudeRateLimitException` (+`retryAfter`) | Да, с ожиданием |
| 5xx | `ClaudeTransientException` | Да |
| Сетевая | `ClaudeTransientException` | Да |

Retry-логика НЕ в клиенте — только классификация ошибок. Вызывающий код решает.

### `App\AI\TaskParser`

Превращает свободный текст в `ParsedTaskDTO`.

**Текущая модель**: `claude-haiku-4-5` — достаточно умная для структурного парсинга, существенно дешевле и быстрее Opus/Sonnet. Переключается через `ANTHROPIC_MODEL_PARSER` в `.env`.

**Процесс**:
1. Собирает system prompt с текущим временем пользователя, его timezone и списком доступных контекстов из БД
2. Отправляет текст через `ClaudeClient::createMessage()` с `temperature=0.0`
3. Парсит JSON-ответ (с fallback: пробует `json_decode`, потом ищет ````json ... ` `` `, потом первый `{ ... }`)
4. Валидирует: priority → enum, contextCodes → только существующие, deadline → в будущем

**Fallback**: если JSON невалиден — создаёт `ParsedTaskDTO(title: $originalText)`. Задача создастся, но без структуры.

## System prompt

```
Ты извлекаешь структуру задачи из свободного текста пользователя.

Текущее время пользователя: {now в формате Y-m-d H:i (l)}
Часовой пояс: {timezone}

Доступные контексты (выбирай ТОЛЬКО из этого списка):
  - at_home: Дома
  - outdoor: На улице / в дороге
  ...

Отвечай строго JSON ...
{JSON schema}

Правила:
1. Title — краткий, императивный, в инфинитиве...
2. Description — null если title всё объясняет...
3. Deadline — ISO 8601 с timezone пользователя...
4. Priority — urgent/high/medium/low...
5. Estimated_minutes — разумная оценка...
6. Context_codes — ТОЛЬКО из списка...
```

### Плейсхолдеры

- `{now}` — текущее время пользователя в его timezone (`$now->setTimezone($userTz)`)
- `{timezone}` — IANA-имя зоны из `$user->getTimezone()`
- Контексты — загружаются из БД (`TaskContextRepository::findAll()`)

## Примеры

| Вход | Title | Deadline | Priority | Contexts |
|---|---|---|---|---|
| «Купить молоко» | Купить молоко | null | medium | [] |
| «Срочно! Забрать документы из МФЦ до 15:00 сегодня» | Забрать документы из МФЦ | сегодня 15:00 | urgent | [at_office] |
| «Позвонить маме, спросить как дела» | Позвонить маме | null | medium | [needs_phone_call] |
| «Завтра утром сходить в поликлинику сдать кровь» | Сдать кровь в поликлинике | завтра 09:00 | medium | [] |
| «На этой неделе написать отчёт, нужен комп и тишина» | Написать отчёт | пятница 18:00 | medium | [needs_internet, focused] |

## Prompt caching

[Anthropic prompt caching](https://docs.anthropic.com/en/docs/build-with-claude/prompt-caching) позволяет пометить части промпта как кешируемые (`cache_control: {type: ephemeral}`), TTL 5 минут. Последующие запросы с тем же префиксом оплачиваются ~10% от обычной стоимости input tokens **и не учитываются в TPM-rate-limit**. Это критично для tier-1 (30k TPM у Sonnet) — без кэша Ассистент с system prompt + 11 tool declarations упирается в лимит при активном использовании.

### Что кешируется

| Вызов | Кешируемые части | Модель |
|---|---|---|
| `Assistant` (свободный текст) | system prompt + все 11 tool definitions | Sonnet 4.6 |
| `TaskAdvisor` (`/free`, `suggest_tasks`) | system prompt (правила подбора) | Sonnet 4.6 |
| `TaskParser` | **не кешируется** | Haiku 4.5 — минимум кеша 2048 токенов, system prompt парсера часто короче, создание кеша без чтений бессмысленно |

### Как работает

Реализация — в `ClaudeClient::createMessage()`, флаги `cacheSystem` / `cacheTools`:

- `cacheSystem=true` — system prompt отправляется как массив блоков с `cache_control` на последнем (и единственном) блоке.
- `cacheTools=true` — ставит `cache_control` на **последний** tool в массиве. Anthropic кэширует всё что до маркера, т.е. весь список tools как единый префикс.
- Порядок tools детерминирован: `ToolRegistry` делает `ksort($byName)` — любая перестановка tools инвалидировала бы весь префикс.

### Silent invalidators (критично!)

Anthropic кэш — это prefix match. **Любой байт в system prompt меняется → весь кэш инвалидируется**. Поэтому мы строго разделяем промпты:

| В system (стабильно — кэш) | В user message (volatile — НЕ кэш) |
|---|---|
| Инструкции, правила, форматы ответа | Текущее время (`now`) — меняется минутно |
| Timezone пользователя (стабилен) | Список активных задач (для advisor) |
| Список контекстов (редко меняется) | Контекст пользователя (`/free`), доступное время |
| Tool definitions | Сообщение пользователя |

Раньше `Assistant::buildSystemPrompt` и `TaskAdvisor::buildSystemPrompt` интерполировали `$nowStr` прямо в промпт → кэш инвалидировался при каждом тике минуты. После рефакторинга время переехало в user message в теге `<context>`.

### Мониторинг эффективности

Каждый вызов `ClaudeClient` логирует:

```json
{
  "model": "claude-sonnet-4-6",
  "input_tokens": 147,
  "cache_read_tokens": 7557,
  "cache_creation_tokens": 0,
  "output_tokens": 71,
  "elapsed": 2.96
}
```

- `cache_read_tokens > 0` + `cache_creation_tokens = 0` → кэш попал, платим 10%.
- `cache_creation_tokens > 0` — первый вызов после 5-минутного простоя, оплата 125% (амортизируется со второго запроса).
- `cache_read_tokens = 0` + `input_tokens` большой — кэш не сработал, ищи silent invalidator.

В `Assistant` дополнительно логируется суммарная статистика по всем итерациям tool-use loop (`Assistant completed` с `cache_read_tokens` / `cache_creation_tokens`).

### Минимальная длина

Anthropic требует минимум **1024 токена для Sonnet/Opus** и **2048 для Haiku** для срабатывания кеша. Если кешируемая часть короче — кэш не создаётся, переплаты тоже нет (молча игнорируется). Наши system + tools у Ассистента (~7к токенов) и system у Advisor'а (~2к) порог превышают.

## Retry-политика

Реализована в `FreeTextHandler::parseWithRetry()`:

| Исключение | Максимум retry | Backoff |
|---|---|---|
| `ClaudeTransientException` | 2 | 1s, 3s (exponential) |
| `ClaudeRateLimitException` | 1 | `retryAfter` или 5s |
| `ClaudeClientException` | 0 | сразу fallback |

При исчерпании retry — fallback на `ParsedTaskDTO(title: $text)`.

Пользователь видит `⚠️ Не смог разобрать задачу, сохранил как есть` только при полном отказе (implicit: задача всё равно создаётся, просто без структуры). В текущей реализации fallback не отправляет отдельного предупреждения — он молча создаёт задачу с сырым title.

## Переменные окружения

| Переменная | Описание | Default |
|---|---|---|
| `ANTHROPIC_API_KEY` | API key от Anthropic | (обязательно) |
| `ANTHROPIC_MODEL_PARSER` | Модель для парсера задач | `claude-haiku-4-5` |

## План расширения

1. **Другие модели per-usecase**: claude-haiku для парсинга задач (текущий), claude-sonnet/opus для сложных задач (подбор рекомендаций, анализ паттернов).
2. **Другие парсеры**: VK-сообщения (тот же TaskParser, другой source), email-парсер.
3. **ResilientTaskParser**: вынести retry-логику из FreeTextHandler в отдельный сервис-обёртку.
4. **Streaming**: для длинных ответов (не нужно для парсинга, может пригодиться для чат-режима).
5. **Cost tracking**: считать стоимость per-user из input/output tokens, хранить в БД.
