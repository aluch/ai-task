# Deployment

Документ описывает прод-окружение AI Task Agent: архитектуру, отличия от dev, процедуру деплоя на VPS, как локально протестировать webhook через ngrok.

## Архитектура prod

```
            ┌─────────────────────────────────────────────────────┐
            │  Caddy (TLS, Let's Encrypt)                         │
Telegram ──→│   ↓                                                 │
            │  POST /api/telegram/webhook/<secret>                │
            │   ↓ FastCGI                                         │
            │  php-fpm (TelegramWebhookController)                │
            │   ↓                                                 │
            │  HandlerRegistry (те же handlers что в polling)     │
            └─────────────────────────────────────────────────────┘
                                  │
                                  ↓
                       ┌─────────────────────┐
                       │ postgres   redis    │
                       └─────────────────────┘
                                  ↑
                       ┌─────────────────────┐
                       │ scheduler (messenger:consume) │
                       └─────────────────────┘
```

**Сервисы в `docker-compose.prod.yml`:**

| Сервис | Что делает |
|---|---|
| `caddy` | TLS-терминирование, Let's Encrypt, FastCGI → php |
| `php` | php-fpm с warmed prod-cache + opcache JIT |
| `postgres` | БД (named volume `postgres_data_prod`) |
| `redis` | Кэш + история диалога + pending actions |
| `scheduler` | messenger:consume для напоминаний (`scheduler_reminders`) |

**Чего нет в prod:** `bot` polling-сервис (его роль играет Caddy + php-webhook), `nginx`, `adminer`. `BotRunCommand` при `TELEGRAM_MODE=webhook` тихо выходит — даже если по ошибке запустить bot-контейнер, polling не активируется.

**Public-папка в Caddy.** `app/public/` маунтится в `caddy` контейнер bind-mount'ом с хоста (`./app/public:/var/www/app/public:ro`). Не named volume — раньше пробовали `php_app_public:` с идеей «Docker инициализирует из php-image», но named volume берёт содержимое из ПЕРВОГО маунтящего контейнера, а startup race с `depends_on` приводил к тому что Caddy получал пустоту. Bind с хоста детерминирован: Caddy видит ровно то же `index.php` что и php-fpm в `COPY --from=composer-stage /app /var/www/app`. `:ro` не даёт Caddy писать в репозиторий.

## Различия dev vs prod

| | dev | prod |
|---|---|---|
| **Telegram entry** | long-polling (BotRunner в bot-сервисе) | webhook (POST в Caddy → php) |
| **Web server** | nginx (на :8080) | Caddy (:80, :443) с Let's Encrypt |
| **APP_ENV** | dev | prod |
| **Cache** | named volumes `bot_cache/scheduler_cache/php_cache`, очищается на старте | warmed в Docker image при build, opcache JIT |
| **Composer** | `install` со всеми зависимостями | `install --no-dev --optimize-autoloader --classmap-authoritative` |
| **opcache** | validate_timestamps=1, revalidate каждый запрос | validate_timestamps=0 (не сверяет mtime) — пересобирается только при rebuild |
| **Postgres port** | торчит наружу (5432) | только внутри docker-сети |
| **adminer** | да (:8081) | нет |
| **Логи** | console + var/log | stdout/stderr (Caddy + php-fpm в docker logs) |

## Переменные окружения для prod

```env
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<random-32-chars>

# Telegram
TELEGRAM_BOT_TOKEN=<from BotFather>
TELEGRAM_ALLOWED_USER_IDS=<your tg id>
TELEGRAM_MODE=webhook
TELEGRAM_WEBHOOK_SECRET=<openssl rand -hex 16>

# Domain (для Caddy и URL вебхука)
DOMAIN=tasks.example.com

# Anthropic
ANTHROPIC_API_KEY=...
ANTHROPIC_MODEL_PARSER=claude-haiku-4-5
ANTHROPIC_MODEL_ADVISOR=claude-sonnet-4-6
ANTHROPIC_MODEL_ASSISTANT=claude-sonnet-4-6

# DB (внутренние, наружу не торчат)
POSTGRES_DB=ai_task
POSTGRES_USER=app
POSTGRES_PASSWORD=<strong>
POSTGRES_VERSION=16
DATABASE_URL="postgresql://app:<password>@postgres:5432/ai_task?serverVersion=16&charset=utf8"
REDIS_URL="redis://redis:6379"
```

`POSTGRES_PORT` / `REDIS_PORT` / `NGINX_PORT` / `ADMINER_PORT` в prod не используются — оставь как есть в `.env`, на работу не влияет.

## Локальное тестирование webhook через ngrok

Перед деплоем на VPS — проверить webhook локально через публичный туннель. **Это обязательный шаг:** smoke-тесты гоняют Assistant напрямую через `Assistant::handle()`, а `TelegramWebhookController` (с его `Update::fromArray()` и runtime-резолвом классов Nutgram) ими НЕ покрыт. Все прежние ошибки в нём (неправильный namespace `Update`-класса и т.п.) ловились только живым прогоном через ngrok.

### 1. Установить ngrok

```bash
# Linux/macOS
brew install ngrok      # или скачать с ngrok.com и распаковать в PATH
ngrok config add-authtoken <TOKEN>
```

### 2. Запустить туннель к локальному nginx (dev compose)

```bash
make up                # обычный dev
ngrok http 8080        # → даст URL вида https://abc123.ngrok-free.app
```

### 3. Подготовить .env

```bash
echo "TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 16)" >> .env
# Переключить bot-сервис в webhook-режим (BotRunCommand тихо выйдет, не
# будет конкурировать с webhook'ом за обновления).
sed -i 's/^TELEGRAM_MODE=.*/TELEGRAM_MODE=webhook/' .env
make bot-restart
```

Если `TELEGRAM_MODE=polling` оставить, Telegram при попытке setWebhook вернёт ошибку — polling и webhook взаимоисключающие.

### 4. Прописать webhook через скрипт (одноразово через `TELEGRAM_WEBHOOK_URL`)

```bash
TELEGRAM_WEBHOOK_URL=https://abc123.ngrok-free.app bin/set-webhook.sh
```

Скрипт постит в Telegram setWebhook с URL `<ngrok>/api/telegram/webhook/<secret>` и `secret_token`.

### 5. Проверить

- Отправить боту `/help` → должен ответить.
- В логах php-контейнера (`docker compose logs -f php`) — записи об обработке update.
- `curl https://abc123.ngrok-free.app/health` → `{"status":"ok"...}`.

Что искать в логах:
- `Class "..." not found` — runtime-резолв сломался (как было с `Update`-namespace).
- `Webhook: invalid JSON` / `Webhook: header secret mismatch` — что-то с конфигом или setWebhook.
- Тишина при отправке сообщения боту — Telegram не дошёл до ngrok (проверь URL и `getWebhookInfo`).

### 6. Вернуть polling

```bash
bin/delete-webhook.sh                     # снимает webhook на стороне Telegram
sed -i 's/^TELEGRAM_MODE=.*/TELEGRAM_MODE=polling/' .env
make bot-restart                          # bot-контейнер заново идёт в polling
```

> ⚠️ **Важно**: пока webhook активен, polling не работает (Telegram не разрешает оба режима одновременно). После теста `delete-webhook.sh` обязателен.

## Первый деплой на VPS

(детальная инструкция — отдельный этап; здесь — каркас)

### Требования

- VPS с Docker + Docker Compose v2
- Доменное имя с A-записью на IP сервера
- Открытые порты 80, 443 (Caddy сам получит сертификат)
- Telegram bot token

### Шаги

1. **Клонировать репозиторий и заполнить .env**
   ```bash
   git clone <repo> /opt/ai-task
   cd /opt/ai-task
   cp .env.example .env
   $EDITOR .env  # заполнить prod-значения (см. выше)
   ```

2. **Запустить deploy.sh**
   ```bash
   bin/deploy.sh
   ```
   Скрипт:
   - `git pull` (на первом запуске — no-op, уже свежее)
   - `docker compose -f docker-compose.prod.yml build`
   - миграции через `docker compose run --rm php`
   - seed контекстов
   - `up -d` всех сервисов
   - `set-webhook.sh` — устанавливает webhook на стороне Telegram

3. **Проверить**
   ```bash
   curl https://${DOMAIN}/health
   # → {"status":"ok","checks":{"db":"ok","redis":"ok"}}

   docker compose -f docker-compose.prod.yml logs caddy | head
   docker compose -f docker-compose.prod.yml logs php | head
   ```

4. **Отправить боту `/help`** → должен ответить.

## Переключение между webhook и polling

Webhook → polling:
```bash
bin/delete-webhook.sh
# измени TELEGRAM_MODE=polling в .env
docker compose restart bot   # если bot-сервис в compose
```

Polling → webhook:
```bash
# измени TELEGRAM_MODE=webhook в .env
bin/set-webhook.sh
```

Webhook URL содержит `TELEGRAM_WEBHOOK_SECRET` из .env. При смене secret обязательно повторно вызвать `set-webhook.sh`.

## Health и мониторинг

Endpoint `/health` возвращает:
- `200 + {"status":"ok"}` — БД и Redis отвечают
- `503 + {"status":"degraded","checks":{"db":"fail: ..."}}` — что-то отвалилось

Время отклика <100мс (без AI-вызовов). Подходит для:
- Caddy healthcheck (см. `docker-compose.prod.yml`)
- Внешний uptime monitor (Uptime Robot, Better Uptime и т.п.)

## Что НЕ настроено сейчас (TODO для следующих этапов)

- CI/CD через GitHub Actions
- Бэкапы Postgres
- Метрики (Prometheus/Grafana)
- Алёрты при ошибках
- Лендинг
