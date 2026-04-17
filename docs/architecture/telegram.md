# Telegram-интеграция

Документ описывает архитектуру Telegram-бота AI Task Agent: компоненты, команды, middleware, конфигурацию и troubleshooting.

## Архитектура

```
┌──────────────┐
│ BotRunCommand│  Symfony Console (app:bot:run)
└──────┬───────┘
       │ run(token)
┌──────▼───────┐
│   BotRunner  │  Создаёт Nutgram, регистрирует handlers, запускает polling
└──────┬───────┘
       │ register(bot)
┌──────▼───────────┐
│ HandlerRegistry  │  Навешивает middleware и handlers на Nutgram
└──────┬───────────┘
       │
┌──────▼────────────────────────────────────────┐
│ Middleware                                     │
│  ┌─────────────────────┐                       │
│  │ WhitelistMiddleware │  → пропускает/блокирует│
│  └─────────────────────┘                       │
│  ┌─────────────────────┐                       │
│  │ EM clear middleware │  → чистит Doctrine EM  │
│  └─────────────────────┘                       │
└────────────────────────────────────────────────┘
       │
┌──────▼────────────────────────────────────────┐
│ Handlers (App\Telegram\Handler\)               │
│  StartHandler   → /start                       │
│  HelpHandler    → /help                        │
│  ListHandler    → /list                        │
│  DoneHandler    → /done <id>                   │
│  SnoozeHandler  → /snooze <id> <when>          │
│  FreeTextHandler→ любой текст без /            │
└────────────────────────────────────────────────┘
       │
┌──────▼───────────────────┐
│ TelegramUserResolver     │  findOrCreate User по telegram_id
└──────────────────────────┘
```

## Компоненты

### `App\Telegram\BotRunner`

Создаёт экземпляр `Nutgram` с переданным токеном, регистрирует handlers через `HandlerRegistry::register()`, добавляет middleware очистки Doctrine EM, запускает long polling (`$bot->run()`). Nutgram не регистрируется как Symfony-сервис — создаётся в runtime, потому что его конструктор бросает исключение при пустом токене.

### `App\Telegram\HandlerRegistry`

Принимает все handler'ы и middleware через DI (autowiring). В `register(Nutgram $bot)` навешивает на бот:
1. `WhitelistMiddleware` — первым, до любой логики
2. Команды: `/start`, `/help`, `/list`, `/done`, `/snooze`
3. Fallback: для текста без `/` — `FreeTextHandler`, для неизвестных команд — подсказка
4. `onException` / `onApiError` — логирование через Monolog + ответ пользователю

### `App\Service\TelegramUserResolver`

Принимает `Nutgram`, возвращает `App\Entity\User`. Логика:
- Ищет по `telegramId` через `UserRepository::findByTelegramId()`
- Если нет — создаёт нового, заполняет `telegramId` и `name` из `from->first_name + last_name`
- Если есть, но `name` не заполнено — обновляет

### `App\Telegram\Middleware\WhitelistMiddleware`

Читает `TELEGRAM_ALLOWED_USER_IDS` (CSV). Если список не пуст — пропускает только перечисленных, остальных логирует и игнорирует. Если пуст — пускает всех.

### Handler'ы (`App\Telegram\Handler\`)

Каждый — invokable класс с `__invoke(Nutgram $bot)`. Зависимости инжектируются через конструктор (Symfony DI autowiring).

## Команды бота

| Команда | Описание |
|---|---|
| `/start` | Регистрация/приветствие |
| `/help` | Список команд |
| `/list` | Открытые задачи (до 10), с дедлайнами в зоне юзера |
| `/done` или `/done <id>` | Пометить задачу выполненной. Без аргументов — inline-кнопки (только незаблокированные). С ID — прямое выполнение |
| `/snooze` или `/snooze <id> <когда>` | Отложить задачу. Без аргументов — двухшаговый flow: выбор задачи → выбор времени (30м/1ч/3ч/завтра 9:00/завтра 18:00/неделя). С аргументами — прямое выполнение |
| `/block` или `/block <task> <blocker>` | Зависимость: task заблокирована blocker'ом. Без аргументов — интерактивный двухшаговый выбор через кнопки |
| `/unblock` или `/unblock <task> <blocker>` | Убрать зависимость. Без аргументов — показывает только задачи с блокерами |
| `/deps` или `/deps <id>` | Показать зависимости задачи (blockedBy + blocking). Без аргументов — inline-кнопки для выбора |
| `/free <время> [контекст]` | AI подбирает задачи под свободное время и контекст. Примеры: `/free 2h`, `/free 30m дома`, `/free 1h на улице`. Ответ — план с inline-кнопками ✅ Беру! / 🔄 Другие варианты / ❌ Не сейчас |
| (свободный текст) | Создать задачу из текста сообщения (AI-парсинг через Claude) |

### Интерактивный flow (inline-кнопки)

Все команды с аргументами поддерживают два режима: с аргументами (CLI/автоматизация) и без (интерактивный через inline-кнопки). При вызове без аргументов бот отправляет сообщение со списком задач как кнопками, пользователь нажимает — бот редактирует сообщение (editMessageText) на результат.

Callback handler'ы:
- `DependencyCallbackHandler` — `dep:s1:*`, `dep:s2:*:*`, `dep:u1:*`, `dep:u2:*:*` (block/unblock flow)
- `TaskActionCallbackHandler` — `done:*` (mark done), `snz:s1:*`/`snz:s2:*:*` (snooze flow), `deps:*` (show deps)
- `FreeCallbackHandler` — `free:<key>:take|reroll|dismiss`. State хранится в Redis (`free:<12hex>`, TTL 1 час) — в callback_data не помещаются UUID задач. Лимит rerolls: 3 подряд. См. `docs/architecture/task-advisor.md`.

Ограничения:
- Максимум 8 кнопок (без пагинации), самые свежие задачи
- Title обрезается до 30 символов с `…`
- Callback_data ≤ 64 байт (Telegram лимит)

## Whitelist

Переменная `TELEGRAM_ALLOWED_USER_IDS` в `.env`:

```
TELEGRAM_ALLOWED_USER_IDS=12345,67890
```

- **Непустая** — только перечисленные `telegram_id` могут взаимодействовать с ботом. Остальные игнорируются, в логах — warning с `telegram_id` и `username`.
- **Пустая** — пускает всех.

Как узнать свой Telegram ID: отправить любое сообщение боту `@userinfobot` или `@getidsbot`.

Как добавить нового пользователя: вписать его `telegram_id` через запятую в `TELEGRAM_ALLOWED_USER_IDS` и перезапустить `make bot-restart`.

## Запуск

### Сервис в docker-compose

```yaml
bot:
  build: ./docker/php
  command: php bin/console app:bot:run -vv
  restart: on-failure
```

Стартует вместе с `make up`. Если `TELEGRAM_BOT_TOKEN` пуст — выходит с 0 (не crash-loop, `on-failure` не перезапускает). Если токен есть — блокируется в long-polling.

### Makefile

```bash
make bot-logs      # tail логов бота
make bot-restart   # перезапустить бота
```

### Ручной запуск (debug)

```bash
make bash
php bin/console app:bot:run -vv
```

## Надёжность long-polling

### Почему бывают сетевые таймауты

Telegram long polling работает так: бот отправляет `getUpdates` с `timeout=30` секунд. Telegram держит соединение до 30 секунд, пока не появятся новые update'ы. Если за 30 секунд ничего не пришло — возвращает пустой ответ и бот снова вызывает `getUpdates`.

Проблема возникает когда HTTP-клиент (Guzzle) закрывает соединение раньше чем Telegram отпустил его. Дефолтный `clientTimeout` у Nutgram — 5 секунд, при `pollingTimeout=10` это гарантированный таймаут при отсутствии активности. Результат: `cURL error 28: Operation timed out`.

### Как мы с ними справляемся

1. **Правильные таймауты.** `clientTimeout=35` > `pollingTimeout=30` с запасом 5 секунд. HTTP-клиент ждёт дольше чем Telegram держит соединение — таймауты практически исключены в нормальных условиях.

2. **Retry loop.** `BotRunner::run()` оборачивает `$bot->run()` в `while`-цикл с `try/catch`:
   - `ConnectException` (таймаут, connection refused) → warning в лог, `sleep(1)`, переподключение
   - `ServerException` (5xx от Telegram) → warning, `sleep(3)`, переподключение
   - Любое другое исключение → пробрасывается, процесс падает, Docker рестартует (фатальная ошибка)

3. **Graceful shutdown.** `SIGTERM`/`SIGINT` обрабатываются через `pcntl_signal()`. При `docker compose stop bot` процесс логирует «Bot stopped gracefully.» и выходит с 0 — без stacktrace и без лишнего рестарта.

### Почему не полагаемся на Docker restart

Docker `restart: on-failure` — это грубый механизм для фатальных ошибок (сегфолт, невалидный конфиг). Транзиентные сетевые сбои — штатная ситуация для long-polling через публичный интернет. Перезапуск процесса на каждый сетевой сбой: (а) теряет 3-5 секунд на пересоздание контейнера, (б) сбрасывает внутреннее состояние бота, (в) захламляет логи Docker. Retry внутри процесса — мгновенный, без потери контекста, с контролируемым `sleep`.

## Troubleshooting

### Бот молчит

1. `make bot-logs` — есть ли `Bot started, polling...`?
2. Если `TELEGRAM_BOT_TOKEN is not set` — заполни токен в `.env` и `make bot-restart`.
3. Если `rejected message from non-whitelisted user` — твой `telegram_id` не в `TELEGRAM_ALLOWED_USER_IDS`.
4. Если `Bot started, polling...` есть, но ответов нет — проверь что ты пишешь именно этому боту (ник бота можно узнать у BotFather).
5. Если `Telegram API error` — проверь что токен корректный (не просроченный, не тестовый).
6. Если `Unhandled bot exception` — смотри stacktrace в логах, исправь код.

### Бот крутится в restart-loop

`restart: on-failure` перезапускает только при exit code != 0. Если бот крашится с исключением — в логах будет stacktrace. Исправь причину. Если нужно временно остановить: `docker compose stop bot`.

### Doctrine EntityManager closed

Долгоживущий polling-процесс может столкнуться с закрытием EM после database-ошибки. BotRunner автоматически вызывает `$doctrine->resetManager()` если EM закрыт, и `$em->clear()` после каждого update. Если проблема повторяется — перезапусти бота.
