# Whitelist & Access Control

Контроль доступа к боту: кто может писать сообщения и пользоваться функционалом. До v2 список allowed-юзеров жил в env-переменной `TELEGRAM_ALLOWED_USER_IDS` — нового друга нельзя было пустить без редеплоя. Теперь — в БД, управляется через `/admin invite` и approve-flow прямо в Telegram.

## Архитектура

```
                            ┌──────────────────┐
   Telegram update          │   WhitelistMW    │
─────────────────────────►  │ (each request)   │
                            └────┬─────────────┘
                                 │ AccessGate.isAllowed(user)
                                 │
                          ┌──────┴──────┐
                          │             │
                       allowed       not allowed
                          │             │
                          ▼             ▼
                       handlers     "🔒 доступ ограничен"
                                    [🙏 Запросить доступ]
                                          │
                                          │ access:request callback
                                          ▼
                                    accessRequestedAt = now
                                    notify admin
                                          │
                                          ▼
                                    [✅ Разрешить] [❌ Отклонить]
                                          │ access:approve|reject:<uuid>
                                          ▼
                                    isAllowed=true   requestRejectedAt=now
                                    (notify user)    (cooldown 30 days)
```

## Схема БД

`users` (миграция `Version20260428000000`):

| Колонка | Тип | Default | Назначение |
|---|---|---|---|
| `is_allowed` | bool | `false` | Главный флаг: пускать ли в бот |
| `access_requested_at` | timestamptz | NULL | Когда нажал «Запросить доступ» |
| `request_rejected_at` | timestamptz | NULL | Когда админ отклонил запрос |

Частичный индекс `idx_users_pending_requests` на `(access_requested_at) WHERE is_allowed=false AND access_requested_at IS NOT NULL` — для быстрого `/admin requests`.

При первой выкатке миграция читает `TELEGRAM_ALLOWED_USER_IDS` из env и делает UPSERT с `is_allowed=true` для каждого ID — сохраняет доступ существующим пользователям. После этого env-переменная не нужна (можно оставить пустой).

## Сервисы

### `App\Service\AccessGate`

Единая точка решения «пускать ли пользователя». Конструктор берёт `ADMIN_TELEGRAM_ID` из env.

- `isAdmin(User)` — bool. Сравнивает `user.telegramId` с `ADMIN_TELEGRAM_ID`.
- `isAllowed(User)` — bool. Админ всегда true (auto-flag'ит `is_allowed=true` если не стояло). Иначе — `user.isAllowed`.
- `canRequestAccess(User, now)` — bool. False если был reject меньше `REJECT_COOLDOWN_DAYS` (30) дней назад — защита от DoS-спама админу.

### `App\Telegram\Middleware\WhitelistMiddleware`

Запускается первым в цепочке. Resolve'ит `User` через `TelegramUserResolver`, проверяет `AccessGate::isAllowed`. Если нет:
1. Уже запрашивал и ждёт ответа → «⏳ Запрос отправлен админу, жди ответа.»
2. Недавно отклонён → silent drop (запись в логи, ничего не отвечаем).
3. Иначе → «🔒 доступ ограничен» + кнопка `[🙏 Запросить доступ]`.

Исключение: callback-query с префиксом `access:*` пропускается дальше — иначе пользователь не сможет нажать «Запросить доступ» (он не allowed).

### `App\Telegram\Handler\AccessRequestCallbackHandler`

Обрабатывает три callback'а:
- `access:request` — пользователь жмёт кнопку. `accessRequestedAt = now`, уведомление админу с inline-кнопками `approve`/`reject`. Сообщение пользователя редактируется на «⏳ Запрос отправлен».
- `access:approve:<user_uuid>` — только админ. `isAllowed=true`, `requestRejectedAt=null`, нотификация юзеру «✅ Доступ открыт». Кнопки убираются с сообщения админа, текст меняется на «✅ Доступ выдан: …».
- `access:reject:<user_uuid>` — только админ. `requestRejectedAt=now`, нотификация юзеру «доступ не одобрен». Cooldown 30 дней (`AccessGate::canRequestAccess`).

### `App\Telegram\Handler\AdminHandler`

`/admin <subcommand>` — доступно только пользователю с `tg_id = ADMIN_TELEGRAM_ID`. Не-админу отвечает как на неизвестную команду — не палит существование admin-функций.

| Команда | Описание |
|---|---|
| `/admin` или `/admin help` | Список subcommands |
| `/admin requests` | Pending-запросы списком (по сообщению на каждого) с inline-кнопками approve/reject |
| `/admin users` | Все allowed-пользователи + count активных задач |
| `/admin invite <tg_id>` | Выдать доступ без запроса (например, друг сказал свой ID лично). Если юзера нет в БД — создаётся stub, имя подтянется при первом /start. |
| `/admin revoke <tg_id>` | Забрать доступ. Нельзя забрать у самого себя (admin auto-allow). |

## Env-переменные

| Переменная | Где | Назначение |
|---|---|---|
| `ADMIN_TELEGRAM_ID` | dev + prod | Telegram ID админа. Один человек. Auto-allow + доступ к `/admin *`. |
| `TELEGRAM_ALLOWED_USER_IDS` | первая миграция | Comma-separated bootstrap-список. После первой выкатки можно оставить пустым (`/admin invite` управляет дальше). |

## Lifecycle юзера

```
[первое сообщение]
        │
        ▼
TelegramUserResolver::resolve → INSERT users (is_allowed=false)
        │
        ▼
WhitelistMiddleware → "доступ ограничен" + [🙏 Запросить доступ]
        │
        │ user clicks button
        ▼
access:request → accessRequestedAt=now → admin gets notification
        │
        │ admin clicks ✅ Разрешить
        ▼
isAllowed=true, accessRequestedAt=null
        │
        ▼
[user → бот] полный доступ
```

Альтернативный путь — админ заранее знает tg_id друга и пишет `/admin invite <id>`. Тогда юзер при первом обращении сразу попадает в бот без шага «запрос — апрув».

## Тестирование

Smoke-сценарии (`make smoke-all`):
- `whitelist-blocks-unknown-user` — gate не пускает юзера с `isAllowed=false`.
- `whitelist-allows-after-approve` — после `setAllowed(true)` gate пускает.
- `admin-invite-creates-allowed-user` — юзер с `isAllowed=true` (имитация invite) проходит gate.
- `whitelist-rejected-cooldown` — `canRequestAccess`=false 1 день после reject, true через 31.

E2E через Telegram (после деплоя): попроси кого-то нового написать боту, проверь что приходит уведомление в твой чат, нажми approve, попроси проверить что бот ответил.

## Что НЕ сделано (намеренно)

- **Несколько админов**: `ADMIN_TELEGRAM_ID` — один ID, не список. Если понадобится — превратить в `users.is_admin` колонку.
- **Bulk-приглашения**: `/admin invite` принимает один ID за раз. Для invite-link'ов или массовых приглашений — отдельная фича.
- **Audit log**: кто, когда, кого invite/revoke — пока только в Monolog. Если нужно для compliance — отдельная таблица `access_audit`.
