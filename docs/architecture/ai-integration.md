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

Принимает `systemPrompt`, `messages`, `model`, `maxTokens`, `temperature`. Возвращает `ClaudeResponse` (DTO: `text`, `stopReason`, `inputTokens`, `outputTokens`, `data`).

**Логирование**: каждый запрос логируется на уровне `info` — модель, input/output tokens, elapsed, stop_reason. Полезно для отладки и оценки стоимости.

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
