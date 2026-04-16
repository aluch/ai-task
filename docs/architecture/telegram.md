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
| `/done <id>` | Пометить задачу выполненной. `<id>` — первые 8+ символов UUID |
| `/snooze <id> <когда>` | Отложить задачу. `<когда>` — `+2h`, `+1d`, `tomorrow 09:00`, `2026-04-20 18:00` |
| (свободный текст) | Создать задачу из текста сообщения |

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
