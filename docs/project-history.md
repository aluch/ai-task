# AI Task Agent — история проекта

Конспект того, что построено, по шагам. Пригодится для онбординга в свежей сессии Claude: архитектура, принятые решения, грабли на которые наступили, правила которые из них вывели.

## TL;DR

**Что это**: персональный Telegram-бот для управления задачами с AI-парсингом и AI-подбором. Пишешь свободным текстом — ассистент на Claude разбирается что ты хочешь (создать задачу, показать список, пометить выполненной, отложить) через tool calling.

**Стек**: PHP 8.3 + Symfony 7.4 + Postgres 16 + Redis 7 + Nginx. Docker Compose. Библиотека Telegram — `nutgram/nutgram` v4, long polling. AI — Anthropic Messages API через Symfony HttpClient.

**Модели**:
- `claude-haiku-4-5` — парсинг задач из текста (экстракция структуры)
- `claude-sonnet-4-6` — подбор задач (`/free`) и ассистент с tool calling (рассуждение)

**Пользовательский UX**:
- Команды (`/list`, `/done`, `/snooze`, `/block`, `/unblock`, `/deps`, `/free`) — быстрый путь, везде inline-кнопки
- Свободный текст → AI-ассистент с 4 tools (create/list/done/snooze)

---

## Этап 1. Docker-окружение

**Коммит `1e0e3b4`** — bootstrap.

Подняли локальное окружение: php-fpm 8.3 (alpine) + nginx 1.27 + postgres 16 (с healthcheck) + redis 7 + adminer. Все порты в `.env`. Именованные volumes для postgres/redis. Раздельные `php-fpm.ini` (256M / 30s) и `php-cli.ini` (1G / 0) с `date.timezone = Europe/Tallinn`.

**Расширения PHP** установлены через pecl/docker-php-ext-install: `pdo_pgsql`, `intl`, `opcache`, `zip`, `bcmath`, `sockets`, `redis`, `pcntl` (последнее для signal handling в long polling).

**Makefile**: `make up/down/restart/build/bash/logs/ps/clean/bot-logs/bot-restart`.

**Правило из этого этапа**: `.gitattributes` с `* text=auto eol=lf` зафиксирован в репо — чтобы `core.autocrlf=true` на хосте не портил LF-файлы.

**Грабля**: на моём WSL2 все `curl localhost:PORT` к контейнерам отдавали «Empty reply from server», потому что в окружении был прописан `LD_PRELOAD=libproxychains.so.4`. Обходится через `LD_PRELOAD= curl ...`. К коду проекта отношения не имеет, но если будешь дебажить — имей в виду.

**Adminer**: свежий образ слушал только на `[::]:8080` (IPv6), пришлось переопределить команду: `command: ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/html"]`.

---

## Этап 2. Symfony 7.4 + бандлы

**Коммит `0917852`** — установка skeleton и базовых пакетов.

Установлен Symfony 7.4 skeleton в `app/` через `composer create-project symfony/skeleton:^7.1 .` (7.1 → последний патч 7.4.x).

Бандлы: `orm-pack` (Doctrine ORM + миграции), `maker-bundle` (dev), `console`, `messenger`, `scheduler`, `http-client`, `uid` (UUID), `monolog-bundle`, `validator`, `nyholm/psr7`.

**Конфигурация**:
- `app/.env.local` задаёт `DATABASE_URL=postgresql://app:app@postgres:5432/ai_task` — хост **`postgres`** (имя сервиса в docker-сети, не `localhost`).
- `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`.

**Правило про права файлов**: все `docker compose exec` к php-контейнеру делаются с флагом `--user 1000:1000` (UID/GID хоста), чтобы composer/maker не создавал файлы под root. В `CLAUDE.md` это зафиксировано.

**Smoke-test команда**: `App\Command\HelloCommand` (`app:hello`) — печатает «AI Task Agent is alive» с timestamp.

---

## Этап 3. Модель данных

**Коммит `5328644`**.

### Сущности

- **`App\Entity\User`**: PK UUID v7, `telegramId` (bigint unique nullable), `name`, `timezone` (default `Europe/Tallinn`), `createdAt`. Связь `OneToMany → Task`.
- **`App\Entity\Task`**: PK UUID v7, FK на User с `ON DELETE CASCADE`. Поля: `title`, `description`, `deadline`, `estimatedMinutes`, `priority`, `status`, `source`, `sourceRef`, `reminderIntervalMinutes`, `lastRemindedAt`, `completedAt`, `createdAt`/`updatedAt` (через TimestampableTrait). `ManyToMany → TaskContext`. Индексы `(user_id, status)` и `(deadline)`.
- **`App\Entity\TaskContext`**: словарь тегов — `code` (unique), `label`, `description`. Заполняется сидером `app:seed:contexts`.

### Enum'ы (`App\Enum\`)

- `TaskPriority`: low/medium/high/urgent
- `TaskStatus`: pending/in_progress/done/cancelled/snoozed
- `TaskSource`: manual/ai_parsed/vk/telegram

Backed string enum, подключены через нативный Doctrine ORM 3 `enumType:` атрибут. В БД — `VARCHAR(16)` с default. Сознательно не используем PG `ENUM` — его сложно мигрировать.

### Трейты

- `CreatedAtTrait`: только `createdAt` + `#[PrePersist]` (для User)
- `TimestampableTrait`: `createdAt` + `updatedAt` + PrePersist/PreUpdate (для Task)

Сущности с трейтами должны быть помечены `#[ORM\HasLifecycleCallbacks]`.

### UUID v7

Все PK — `Symfony\Component\Uid\Uuid::v7()`. В Postgres хранятся как нативный `UUID` (не CHAR(36), не BINARY(16)) — для этого в `config/packages/doctrine.yaml` зарегистрирован тип `uuid: Symfony\Bridge\Doctrine\Types\UuidType`. Без регистрации `make:migration` сгенерил бы BINARY(16) или CHAR(36).

**Почему v7, а не v4**: первые 48 бит UUID v7 — unix timestamp в мс. Индекс PK естественно растёт во времени (меньше фрагментации B-tree), по id видно когда создана запись, можно сортировать по id вместо created_at.

### CLI команды (для отладки модели)

- `app:seed:contexts` — идемпотентный upsert словаря контекстов
- `app:user:create --telegram-id=X --name=Y`
- `app:user:list`
- `app:task:create --user-id=<uuid|tg-id> --title=... --priority=high --contexts=at_home,quick`
- `app:task:list --user-id=X [--status=done] [--limit=20]`
- `app:task:done <task-uuid>`
- `app:task:snooze <task-uuid> "+2 hours"`
- `app:task:block <blocked-uuid> <blocker-uuid>` (добавлено позже)
- `app:task:deps <task-uuid>` (добавлено позже)

`UserRepository::findByIdentifier()` принимает и UUID, и telegram-id — чтобы CLI мог работать с обоими.

---

## Этап 3.5. TIMESTAMPTZ и snoozedUntil

**Коммит `02dffea`**.

Изначально datetime-поля были `TIMESTAMP WITHOUT TIME ZONE` (дефолт Doctrine). Передумали в пользу `TIMESTAMPTZ` — приложение мультипользовательское с per-user timezone, хранить голые TIMESTAMP значило закладывать мину.

**Фикс**:
- Все datetime → `Types::DATETIMETZ_IMMUTABLE`
- Все `new DateTimeImmutable()` с явной зоной UTC
- Парсинг пользовательского ввода — в зоне юзера, `setTimezone('UTC')` перед сохранением
- Вывод — `setTimezone($userTz)` перед `format()`

**Миграция**: `ALTER COLUMN TYPE TIMESTAMP WITH TIME ZONE USING <col> AT TIME ZONE 'Europe/Tallinn'` — важно явно указать `USING`, иначе postgres интерпретирует naive timestamps в зоне сессии БД (лотерея). Без `USING` существующие значения «уплыли» бы на часы.

**Добавлен `Task.snoozedUntil`** (`datetimetz_immutable`, nullable) + метод `Task::snooze(\DateTimeImmutable $until)`. Логика: статус SNOOZED + `snoozedUntil` = момент пробуждения. В `findForUser()` без фильтра активные SNOOZED скрываются через WHERE.

**`App\Service\RelativeTimeParser`** — парсер относительных/абсолютных форматов времени (`+2h`, `+1d`, `tomorrow 09:00`, `2026-04-20 18:00`). Используется и в CLI, и в боте.

**Важная грабля с PHP и «+2h»**: PHP парсит `new DateTimeImmutable("+2h", $tz)` не как «через 2 часа», а как **фиксированный TZ-офсет +02:00**. Короткая форма `2h` совпадает с синтаксисом зоны. То же для `modify("+2h")`. Поэтому `RelativeTimeParser` расширяет короткие формы (`+2h` → `+2 hours`, `-30m` → `-30 minutes`) regex'ом перед `modify()`. Задокументировано в `docs/architecture/data-model.md` секция «Работа со временем».

---

## Этап 4. Telegram-бот (long polling)

**Коммит `2d84adc`**.

Библиотека **`nutgram/nutgram`** v4. Режим long polling.

### Компоненты

- **`App\Telegram\BotRunner`** — создаёт Nutgram в runtime (НЕ через DI — конструктор Nutgram бросает исключение при пустом токене, что ломало бы контейнер), регистрирует handlers через HandlerRegistry, запускает polling.
- **`App\Telegram\HandlerRegistry`** — регистрирует middleware и handlers на инстансе Nutgram.
- **`App\Telegram\Handler\`** — handler-классы (invokable).
- **`App\Telegram\Middleware\WhitelistMiddleware`** — фильтр по CSV `TELEGRAM_ALLOWED_USER_IDS`. Если список пуст — пускает всех.
- **`App\Service\TelegramUserResolver`** — find-or-create User по telegram_id.
- **`App\Command\BotRunCommand`** (`app:bot:run`) — проверяет токен; если пустой → warning + exit 0 (Docker `restart: on-failure` не перезапустит).

### Команды бота (начальный набор)

- `/start` — регистрация + приветствие
- `/help` — справка
- `/list` — открытые задачи
- `/done <id>` — пометить выполненной
- `/snooze <id> <когда>` — отложить

Свободный текст → `FreeTextHandler` (в начальной версии просто создавал задачу с `title = text`).

### docker-compose bot-сервис

```yaml
bot:
  command: php bin/console app:bot:run -vv
  restart: on-failure      # exit 0 при пустом токене → не рестартит
  stop_grace_period: 35s   # больше polling timeout
```

### Грабли этапа

**1. Nutgram command matching.** `onCommand('done', $handler)` создаёт regex `/^\/done$/mu` — матчит только голый `/done` без аргументов. `/done abc` не матчится, падает в fallback.

**Фикс (коммит `d5d8fcd`)**: каждая команда с аргументами регистрируется в двух вариантах:
```php
$bot->onCommand('done', $handler);        // голый /done → usage
$bot->onCommand('done {args}', $handler); // /done X → обработка
```
`{args}` создаёт `/^\/done (?<args>.*?)$/mu` и передаёт захват как второй параметр в `__invoke`. Сигнатуры обновлены: `__invoke(Nutgram $bot, ?string $args = null)`.

**2. `env_file` в docker-compose и переменные.** `env_file: .env` фиксируется при СОЗДАНИИ контейнера. Если добавил ANTHROPIC_API_KEY в `.env` после `docker compose up` — контейнер не видит. `docker compose restart` не помогает, нужен `down && up`.

**Фикс (коммит `718f213`)**: общая конфигурация PHP-сервисов вынесена в YAML-якорь `x-php-service: &php-service` с явными `environment:` записями (имеют приоритет над `env_file`). `php` и `bot` наследуют через `<<: *php-service`, добавляя только специфичные поля (container_name, command, restart).

---

## Этап 4.5. Resilient long polling

**Коммит `81f7255`**.

**Проблема**: в логах периодически `cURL error 28: Operation timed out after 11001 milliseconds` при `getUpdates`. Процесс падал, Docker рестартил.

**Причина**: дефолтный `clientTimeout` у Nutgram — 5 секунд при `pollingTimeout=10`. HTTP-клиент закрывает соединение раньше чем Telegram отпускает. Гарантированный таймаут при отсутствии активности.

**Фикс**:
- `clientTimeout: 35` > `pollingTimeout: 30` с запасом 5 секунд
- `BotRunner::run()` оборачивает `$bot->run()` в `while(true)` с `try/catch`:
  - `ConnectException` → warning, `sleep(1)`, новый бот-инстанс
  - `ServerException` (5xx) → warning, `sleep(3)`, retry
  - Нормальный возврат из `run()` → break (graceful shutdown)

**SIGTERM правильно.** Nutgram сам обрабатывает сигналы (`Polling::$FOREVER = false` по SIGTERM). Если SIGTERM прерывает cURL и бросает ConnectException, проверяем `Polling::$FOREVER` — если false, выходим вместо retry. Перед retry сбрасываем `$FOREVER = true`.

**Docker**: `stop_grace_period: 35s` для bot-сервиса — даёт cURL время завершить текущий 30-секундный poll до SIGKILL.

---

## Этап 5. AI-парсинг задач

**Коммит `bdd69bd`**.

### Компоненты

- **`App\AI\ClaudeClient`** — обёртка над Symfony HttpClient для Anthropic Messages API. Классифицирует ошибки:
  - `ClaudeClientException` (4xx) — ошибка запроса, не retry
  - `ClaudeTransientException` (5xx/сеть) — retry
  - `ClaudeRateLimitException` (429) — retry с `retryAfter` если есть
- **`App\AI\TaskParser`** — свободный текст → `ParsedTaskDTO` (title, description, deadline, estimatedMinutes, priority, contextCodes, parserNotes).
- **`App\AI\DTO\ClaudeResponse`** — DTO ответа (text, stopReason, inputTokens, outputTokens, data).
- **`App\AI\DTO\ParsedTaskDTO`** — immutable DTO задачи.

### System prompt парсера

Содержит:
- Текущее время пользователя в его timezone
- Timezone (IANA-имя)
- Список контекстов из БД (code → label)
- Инструкцию отвечать строго JSON в фиксированной схеме
- Правила парсинга (title в инфинитиве, интерпретация дедлайнов, приоритеты, контексты)

### Модель

`claude-haiku-4-5` (env `ANTHROPIC_MODEL_PARSER`). Temperature=0.0 для детерминизма. Haiku достаточно умна для структурной экстракции, значительно дешевле Sonnet/Opus.

### JSON-парсинг

Тройной fallback: прямой `json_decode` → markdown block ```json ... ``` → первый `{ ... }` в тексте. Если всё провалилось — `ParsedTaskDTO(title: $originalText)`, задача создастся без структуры.

### FreeTextHandler после интеграции

1. `⏳ Разбираю задачу...`
2. `TaskParser::parse()` с retry (2 попытки при transient, 1 при rate limit)
3. `Task` с `source=AI_PARSED`, `sourceRef=telegram_message_id`
4. Ответ: title + description + deadline + estimate + priority + contexts

### Reasoning-поля в JSON-схеме

`deadline_reasoning` и `priority_reasoning` — не попадают в DTO, но улучшают качество через chain-of-thought. Логируются на debug-уровне для отладки промпта.

### Тюнинг промпта (коммит `5be4228`)

По результатам тестирования:
1. `estimated_minutes` заполнялся плохо → добавлены явные примеры (купить продукты ~30 мин, позвонить ~10, МФЦ ~60)
2. Контекст `quick` ставился агрессивно → жёсткое правило «quick ТОЛЬКО при estimated_minutes ≤ 15»
3. «завтра» без времени → было 09:00, исправлено на 18:00
4. `description` не заполнялся → усилено правило «если больше одного предложения или есть детали — обязательно»

---

## Этап 5.5. Форматирование ответа (коммит `6486094`)

Description визуально сливался с title. Добавил разделение:

```
✅ Задача создана

📝 Title

💬 Description

⏰ deadline
⏱ estimate
🏷 contexts
```

Пустая строка после title, иконка 💬 перед description, пустая строка после — три визуальных блока.

---

## Этап 6. Task dependencies (blocking)

**Коммит `5db382d`**.

### Модель

- `Task.blockedBy: ManyToMany(self)` — join table `task_dependencies (blocked_task_id, blocker_task_id)`. PK на паре (уникальность), оба FK с `ON DELETE CASCADE`.
- `Task.blocking` — обратная сторона (`mappedBy`).
- Методы: `addBlocker()` (LogicException при самоблокировке), `removeBlocker()`, `isBlocked()` (есть блокеры не в DONE/CANCELLED), `getActiveBlockers()`, `getBlockedTasks()`.

### Валидация циклов

`App\Service\DependencyValidator::validateNoCycle(blocked, blocker)` — DFS от blocker по `blockedBy`. Если достигнем blocked — `CyclicDependencyException`.

### Команды бота

- `/block <task> <blocker>` — семантика: «task заблокирована blocker'ом»
- `/unblock <task> <blocker>`
- `/deps <task>` — показать зависимости в обе стороны

### Auto-unblock в DoneHandler

После `markDone` проверяет `getBlockedTasks()` — если у downstream-задачи больше нет активных блокеров, выводит `🔓 Разблокирована:` со списком.

### ListHandler

Заблокированные задачи показываются в конце списка с пометкой `⛔`, незаблокированные — вверху.

### Repository

`TaskRepository::findUnblockedForUser()` — PHP-фильтрация по `isBlocked()`. TODO: для масштабирования заменить на SQL subquery.

---

## Этап 6.5. Интерактивный flow через inline-кнопки

**Коммиты `af707f7`** (/block, /unblock) и **`a9af783`** (/done, /snooze, /deps).

Все команды с аргументами теперь поддерживают два режима:
- С аргументами — CLI-удобство, автоматизация
- Без аргументов — inline-кнопки со списком задач

### Грабли с callback_data

Telegram ограничивает `callback_data` до 64 байт. Несколько полных UUID не влезают.

**Для одно-UUID колбэков** (`done:<uuid>`, `snz:s1:<uuid>`, `dep:s1:<uuid>`, `deps:<uuid>`) — полный UUID (36 chars) влезает (41-43 байта).

**Для двух-UUID колбэков** (`dep:s2:<blocked>:<blocker>`, `dep:u2:...`) — два UUID = 80 байт, не влезают. **Решение**: `App\Service\DependencyStateStore` — хранит blocked_uuid в Redis (TTL 10 мин, ключ 12hex). Шаг 1 → сохранить state, шаг 2 → `dep:s2:<stateKey>:<blocker_full_uuid>` = 56 байт.

**Для `/snooze` preset'ов** (`snz:s2:<uuid>:<preset>`) — полный UUID + короткий preset (`30m`, `1h`, `3h`, `tom9`, `tom18`, `1w`) = ~49 байт, влезает.

### Callback handlers

- `DependencyCallbackHandler` — `dep:s1:*`, `dep:s2:*:*`, `dep:u1:*`, `dep:u2:*:*`
- `TaskActionCallbackHandler` — `done:*`, `snz:s1:*`/`snz:s2:*:*`, `deps:*`

Регистрация через `$bot->onCallbackQueryData('dep:{data}', handler)`.

### Common patterns в callback handlers

После step 1 выбора задачи — `editMessageText` с новой клавиатурой (шаг 2). После финального действия — `editMessageText` без клавиатуры (результат). Все callback'и начинаются с `$bot->answerCallbackQuery()` чтобы убрать «загрузку» на кнопке.

---

## Этап 6.75. EM lifecycle в long-running процессах

**Коммиты `df0c1a5`, `cc0e8bd`, `5750eee`, `6578899`** — серия критичных фиксов.

### Баг 1: Stale identity map (`df0c1a5`)

**Симптом**: изменения задач из предыдущих update'ов проявлялись в следующих — `findBy` возвращал кешированные сущности с устаревшим статусом.

**Причина**: middleware чистил `$em->clear()` только в `finally` (после handler). Между update'ами clear был, но если handler добавил данные в identity map — они жили до следующего цикла, где уже могли быть stale.

**Ещё хуже**: `$em` захвачен в closure как конкретная ссылка. После `resetManager()` переменная указывает на закрытый старый EM — все последующие `clear()` либо падают, либо молча не работают.

**Фикс**:
1. `clear` ДО `$next($bot)` и ПОСЛЕ (в finally) — двойная страховка
2. Вынесено в `cleanEm(ManagerRegistry $doctrine)` — каждый раз фетчит EM из registry свежим через `$doctrine->getManager()`

### Баг 2: DoneCallback не персистит изменения в БД (`5750eee`)

**Симптом**: нажатие кнопки «Выполнил» → бот пишет «✅ выполнено», но `/list` всё ещё показывает задачу как PENDING. В БД ничего не записано.

**Root cause — classic long-running Doctrine**:
- `TaskRepository extends ServiceEntityRepository` захватывает EM в конструкторе
- `TelegramUserResolver` инжектил `EntityManagerInterface` напрямую
- Когда-то в сессии сработал `$doctrine->resetManager()` (после ORMException или закрытого EM)
- Registry начал отдавать НОВЫЙ EM, но repo и resolver продолжали держать СТАРЫЙ

Поток при нажатии «Выполнил»:
```
$this->tasks->find($uuid)         → task в identity map СТАРОГО EM
$task->markDone()                 → изменения в СТАРОМ EM
$this->doctrine->getManager()->flush()  → flush НОВОГО EM → нечего flush
```

Пользователю отправлено «успех», но в БД ничего не записано.

**Фикс**:
1. `TelegramUserResolver` → `ManagerRegistry` вместо прямого EM
2. `TaskIdResolver` → `ManagerRegistry` + `$em->getRepository()` каждый раз
3. Callback handlers (`TaskActionCallbackHandler`, `FreeCallbackHandler::handleTake`) получают repo из **текущего** EM через `$em->getRepository(Task::class)`
4. Защита `$em->contains($task)` в мутационных хендлерах — если task пришёл из stale EM, `$em->find()` по ID подгружает его в current EM перед мутацией
5. Info-логи `DoneCallback: marking done {status_before}` / `DoneCallback: flushed {status_after}` для диагностики

### Правило из этих багов (`cc0e8bd`, `6578899`)

Задокументировано в `CLAUDE.md` → «Long-running процессы и Doctrine»:

> В долгоживущих процессах (Telegram-бот, Messenger workers, Scheduler) **никогда не инжектить `EntityManagerInterface` напрямую** и **никогда не инжектить Doctrine-репозитории через конструктор**. ServiceEntityRepository захватывает EM в `$_em` при старте; после `resetManager()` это stale ссылка.
>
> Правильный паттерн:
> ```php
> public function __construct(private ManagerRegistry $doctrine) {}
>
> $em = $this->doctrine->getManager();
> $repo = $em->getRepository(Task::class);
> $task = $repo->find($uuid);
> $em->flush();
> ```

Исключение: короткоживущие HTTP-контроллеры и обычные CLI-команды — там прямая инжекция безопасна.

---

## Этап 7. `/free` — AI-подбор задач

**Коммит `dd68c5d`**.

Главная фича проекта. `/free 2h дома` → Claude Sonnet подбирает оптимальный набор задач под время и контекст.

### Компоненты

- **`App\AI\TaskAdvisor`** — аналогично `TaskParser` но для рассуждения. Модель `claude-sonnet-4-6` (env `ANTHROPIC_MODEL_ADVISOR`). Temperature=0.3 для вариативности reroll. Max tokens 2048.
- **`App\AI\DTO\TaskSuggestionDTO`** + `SuggestedTask` — DTO подборки (suggestions, userSummary, internalReasoning, totalEstimatedMinutes, noMatchReason).
- **`App\Service\FreeSuggestionStore`** — Redis-хранилище состояния предложения. Ключ `free:<12hex>`, TTL 1 час. Хранит `user_id`, `task_ids`, `minutes`, `context`, `excluded_ids`, `reroll_count`.
- **`App\Telegram\Handler\FreeHandler`** — обработка `/free <время> [контекст]`.
- **`App\Telegram\Handler\FreeCallbackHandler`** — обработка inline-кнопок `free:<key>:take|reroll|dismiss`.

### System prompt advisor

9 правил подбора (приоритет/срочность, уложиться во время, контекст места, группировка по маршруту, зависимости, порядок выполнения, tip, пустой результат, переполнение).

Правило №3 (контекст места) расписано как явная таблица сопоставлений:
- «дома» → at_home, исключает outdoor/at_dacha/at_office
- «на даче» → at_dacha + outdoor
- «на улице» → outdoor, исключает focused/needs_internet
- «в офисе» → at_office + focused + needs_internet

### Inline-кнопки

- **✅ Беру!** → все task_ids → `IN_PROGRESS`, ключ удаляется
- **🔄 Другие варианты** → advisor с `excludeTaskIds = previous + accumulated`, `reroll_count++`, max 3 (на 4-й — «больше ничего не подобрать»)
- **❌ Не сейчас** → «Ок, отдыхай! 🌴»

### Retry политика

Та же что в TaskParser: 2 попытки при transient, 1 при rate limit (с `retryAfter`), 0 при client error.

### Тюнинг промпта (коммит `9929321`)

Ответы `/free` были слишком длинными — пользователь видел перечисление всех отвергнутых задач с причинами.

**Фикс**: разделил reasoning на два поля в JSON-схеме:
- `user_summary` — для пользователя, 1-3 предложения, ≤300 символов, без перечисления отвергнутых, без нумерации. Идёт в Telegram в блок 💭.
- `internal_reasoning` — подробный анализ для логов. Пользователь не видит.

Валидация длины `user_summary`: если модель выдала >300 — обрезаем до 299 с `…` и warning в логи.

`internal_reasoning` логируется в `FreeHandler::suggestWithRetry()` с контекстом (user_id, available_minutes, context, suggestions_count) — удобно `grep`ать при анализе.

### `RelativeTimeParser::parseToMinutes()`

Парсит длительность: `30m`, `1h`, `1.5h`, `90m`, `2ч`, `45м`, `1,5h`, `120` (число = минуты). Используется в `/free` и `/snooze`.

---

## Этап 7.5. Фикс коллизии short_id и lazy reactivation

**Коммит `38b40eb`**.

### Баг 1: Коллизия short_id

UUID v7 сортируем по времени с разрешением мс, а 8 hex-символов дают разрешение ~16 секунд. Задачи, созданные быстро подряд, получали одинаковый 8-символьный префикс.

Реальный тест: три задачи с `019d9c5e-22d8-...`, `019d9c5e-23b4-...`, `019d9c5e-247f-...` — одинаковый префикс `019d9c5e`. Callback'и и lookup по префиксу ломались.

**Фикс**:
1. **Display** — удалил `ID: <short>` из user-facing отображения (ListHandler, FreeTextHandler). Пользователь не вводит ID руками — везде inline-кнопки. В `/deps` оставил full UUID (1-2 штуки, полезно для отладки). В CLI full UUID тоже оставил.
2. **Callback_data** — switch на full UUID для single-id колбэков (`done:<36>`, `snz:s1:<36>`, `deps:<36>`, `dep:s1:<36>`, `dep:u1:<36>`). Для двух-UUID (`dep:s2`/`u2`) — `DependencyStateStore` (уже было, работает).
3. **`App\Service\TaskIdResolver`** — единая точка резолва ID из CLI-аргументов:
   - Full UUID → direct find
   - 8-35 символов → prefix match + проверка уникальности (>1 → «неоднозначно, используй full UUID»)
   - <8 символов → ошибка

### Баг 2: SNOOZED не разбуживаются

Задача с `snoozedUntil` в прошлом продолжала показываться как SNOOZED.

**Фикс**: **`App\Service\SnoozeReactivator::reactivateExpired(User): int`** — QB `WHERE status=SNOOZED AND snoozed_until <= now`, bulk reactivate, flush. Пусто → в БД не пишет.

Интеграция через **setter injection** в `TaskRepository::setReactivator()` (через конструктор цикл: Reactivator → EM → Repository). Вызывается в `findForUser()` перед каждой выборкой. `findUnblockedForUser` использует `findForUser` → транзитивно работает.

`Task::reactivate()` — status=PENDING, snoozedUntil=null. Логирование info на каждую реактивацию.

Интерфейс `reactivateExpired(User)` спроектирован так, чтобы вызываться из будущего Scheduler handler'а (Этап 8) без изменений.

---

## Этап 7.6. Фильтры `/list`

**Коммит `024a10a`**.

Баг: `/list` показывал задачи в DONE.

**Root cause**: `TaskRepository::findForUser()` без явного status скрывал только active SNOOZED, но не фильтровал по активным статусам — DONE/CANCELLED просачивались.

**Фикс**: сигнатура `findForUser(User, ?TaskStatus[] $statuses = null)`:
- `null` (default) → `[PENDING, IN_PROGRESS]` (константа `ACTIVE_STATUSES`)
- `[]` → все статусы
- Конкретный массив → только эти

**`/list`** поддерживает аргументы:
- `/list` → active (default)
- `/list все` / `all` → все
- `/list done` / `выполнено` → DONE
- `/list snoozed` / `отложенные` → SNOOZED

При фильтре — заголовок `📋 Фильтр: <название>`.

**Карта вызовов после фикса**:

| Handler | Метод | Фильтр |
|---|---|---|
| ListHandler | findForUser | active / по arg |
| DoneHandler::showInteractive | findUnblockedForUser | active + unblocked |
| SnoozeHandler::showInteractive | findForUser | active |
| DepsHandler::showInteractive | findForUser | active |
| BlockHandler::startInteractiveFlow | findForUser | active |
| UnblockHandler::startInteractiveFlow | findForUser | active + есть блокеры |
| FreeHandler::loadAvailableTasks | findUnblockedForUser | active + unblocked |
| FreeCallbackHandler::handleReroll | findUnblockedForUser | active + unblocked |
| DependencyCallbackHandler::step1 | findForUser | active |
| TaskListCommand (CLI) | findForUser | по `--status` |

Везде default = active → DONE/CANCELLED теперь не просачиваются никуда.

---

## Этап 7.7. i18n контекстов

**Коммит `7d0ba63`**.

Контексты показывались в Telegram как коды (`outdoor`, `needs_internet`) — неудобно.

### Изменения

**TaskContext уже имел русские labels** в сидере (`at_home → «Дома»`, `outdoor → «На улице / в дороге»` и т.д.). Правильные. Плюс сидер переделан из skip-existing в upsert — по `code` сравнивает `label`/`description` и обновляет при отличии.

**`FreeTextHandler::formatResponse`** — теперь берёт контексты из `$task->getContexts()`, вызывает `getLabel()`, склеивает через запятую. Пользователь видит `🏷 Нужен интернет, Короткая` вместо `needs_internet, quick`.

**TaskAdvisor system prompt** — правило №3 про контекст места переписано как явная таблица сопоставлений русских выражений с машинными кодами.

**Разделение слоёв**:
- User-facing (Telegram) — русские лейблы через `getLabel()`
- Внутренние API (AI JSON, callback_data, логи, CLI-таблицы) — машинные коды

---

## Этап 8. AI-ассистент с tool calling

**Коммит `80e940b`**. Текущий этап.

### Концепция

**Было**: свободный текст → `FreeTextHandler` → `TaskParser` → создание задачи. Всегда одна операция.

**Стало**: свободный текст → `AssistantHandler` → `Assistant` → Claude с tools → Claude сам выбирает что делать (создать, список, пометить выполненной, отложить) → исполнение через существующие сервисы → ответ пользователю.

Команды (`/list`, `/done`, `/free` и т.д.) остаются быстрым путём. Ассистент — для свободного текста.

### Архитектура

- **`App\AI\Assistant`** — центральный класс. `handle(User, string, DateTimeImmutable)`. Tool use loop до 5 итераций. Модель `claude-sonnet-4-6` (env `ANTHROPIC_MODEL_ASSISTANT`), temperature 0.5, max_tokens 1500.
- **`App\AI\Tool\AssistantTool`** — интерфейс (`getName/getDescription/getInputSchema/execute`).
- **`App\AI\Tool\ToolRegistry`** — автосбор через `_instanceof` + `tagged_iterator` в `services.yaml`. Любой класс, реализующий `AssistantTool`, автоматически попадает в registry. Ошибки tool'а ловятся и превращаются в `ToolResult(success=false)`.
- **`App\AI\Tool\ToolResult`** — DTO (`success`, `content`, `metadata`).
- **`App\AI\DTO\AssistantResult`** — DTO результата (`replyText`, `toolsCalled`, `inputTokens`, `outputTokens`, `iterations`).

### Расширение ClaudeClient

`createMessage()` получил параметр `?array $tools` и поддержку `content` как array (для tool_result блоков). Protocol Anthropic:
- Assistant response с `stop_reason == "tool_use"` → content блоки содержат `{type: "tool_use", id, name, input}`
- Следующее сообщение от user должно содержать `{type: "tool_result", tool_use_id, content, is_error}` для каждого tool_use

### 4 Tool'а (MVP)

| Tool | Что делает | Input |
|---|---|---|
| `create_task` | Обёртка над TaskParser, source=AI_PARSED | `{raw_text: string}` |
| `list_tasks` | Фильтры по статусу + query по title + limit | `{status_filter?, query?, limit?}` |
| `mark_task_done` | По task_id или task_query | `{task_id?, task_query?}` |
| `snooze_task` | По task_id или task_query + until_iso в зоне юзера | `{task_id?, task_query?, until_iso}` |

Для `mark_task_done` и `snooze_task` — если task_query даёт >1 совпадение → `ToolResult(success=false)` со списком, Claude спрашивает пользователя.

### System prompt ассистента

Структура:
- Роль + текущее время в зоне юзера
- 5 сценариев (новая задача / вопрос про задачи / сделал / отложить / приветствие)
- Принципы: лаконичность 1-3 предложения, используй tools без лишних вопросов, уточняй только при неоднозначности
- Интерпретация времени (завтра=18:00, завтра утром=09:00, через час = now+1h)
- Плохо/хорошо-примеры ответа: не показывать UUID, имена tools, технические детали

### AssistantHandler

Зарегистрирован на fallback вместо `FreeTextHandler`. Отправляет `🤔 Думаю...`, вызывает Assistant, редактирует сообщение на результат. При исключении → `⚠️ Что-то пошло не так`. Логирует `tools_called`, `iterations`, tokens.

`FreeTextHandler` оставлен в коде как быстрый откат — если assistant начнёт сбоить, одна строка в `HandlerRegistry::register()` возвращает старое поведение.

### Как добавить новый tool

1. Класс в `App\AI\Tool\`, реализовать `AssistantTool`
2. Всё. Автоконфиг тегирует, ToolRegistry подхватывает через tagged_iterator

Переписывать handler'ы или регистрацию не надо — Claude увидит новый tool в системном промпте (через `getAnthropicSchemas()`) и начнёт использовать когда сочтёт уместным.

### Известные ограничения (будут в следующих этапах)

- Нет истории диалога — каждое сообщение как новая сессия
- Нет Reply-механики
- Нет tools для редактирования (update_task)
- Нет tool для `/free`-подбора
- Нет tools для блокировок

---

## Структура проекта (финальная)

```
ai-task/
├── app/                                # Symfony 7.4
│   ├── config/
│   │   ├── packages/
│   │   │   └── doctrine.yaml           # UUID type, naming strategy
│   │   └── services.yaml               # DI: parameters, _instanceof для AssistantTool
│   ├── migrations/                     # 3 миграции
│   └── src/
│       ├── AI/
│       │   ├── Assistant.php           # Tool use loop
│       │   ├── ClaudeClient.php        # HTTP-клиент Anthropic API
│       │   ├── TaskAdvisor.php         # /free — подбор задач
│       │   ├── TaskParser.php          # Экстракция структуры из текста
│       │   ├── DTO/                    # ClaudeResponse, ParsedTaskDTO,
│       │   │                           # TaskSuggestionDTO, SuggestedTask, AssistantResult
│       │   ├── Exception/              # ClaudeClientException/TransientException/RateLimitException
│       │   └── Tool/                   # AssistantTool, ToolRegistry, ToolResult,
│       │                               # CreateTaskTool, ListTasksTool, MarkTaskDoneTool, SnoozeTaskTool
│       ├── Command/                    # app:bot:run + CLI CRUD для задач
│       ├── Entity/
│       │   ├── Task.php                # 👈 центр домена
│       │   ├── TaskContext.php
│       │   ├── User.php
│       │   └── Trait/                  # CreatedAtTrait, TimestampableTrait
│       ├── Enum/                       # TaskPriority, TaskStatus, TaskSource
│       ├── Exception/                  # CyclicDependencyException, TaskIdException
│       ├── Repository/                 # TaskRepository с setReactivator, UserRepository, TaskContextRepository
│       ├── Service/
│       │   ├── DependencyStateStore.php # Redis state для dep:s2/u2 колбэков
│       │   ├── DependencyValidator.php  # DFS циклов
│       │   ├── FreeSuggestionStore.php  # Redis state для /free
│       │   ├── RelativeTimeParser.php   # Парсинг +2h / tomorrow 9am
│       │   ├── SnoozeReactivator.php    # Lazy reactivation SNOOZED
│       │   ├── TaskIdResolver.php       # Full UUID / prefix / error
│       │   └── TelegramUserResolver.php # find-or-create User
│       └── Telegram/
│           ├── BotRunner.php            # Long polling с retry loop
│           ├── HandlerRegistry.php      # Регистрация handlers и middleware
│           ├── Handler/                 # StartHandler, HelpHandler, ListHandler,
│           │                            # DoneHandler, SnoozeHandler, BlockHandler, UnblockHandler,
│           │                            # DepsHandler, FreeHandler, FreeTextHandler (legacy),
│           │                            # AssistantHandler, DependencyCallbackHandler,
│           │                            # TaskActionCallbackHandler, FreeCallbackHandler
│           └── Middleware/
│               └── WhitelistMiddleware.php
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile + php-fpm.ini + php-cli.ini
├── docker-compose.yml                  # 6 сервисов, x-php-service якорь
├── docs/architecture/
│   ├── ai-integration.md               # TaskParser, ClaudeClient
│   ├── assistant.md                    # Assistant с tool calling
│   ├── data-model.md                   # Сущности, ER-диаграмма, работа со временем
│   ├── task-advisor.md                 # /free
│   └── telegram.md                     # Бот, команды, callback protocol
├── CLAUDE.md                           # Проектные инструкции: стек, конвенции, правила
├── README.md
├── Makefile
└── .env / .env.example
```

---

## Ключевые правила из проекта (в CLAUDE.md)

### Время — всё в UTC

В БД все datetime-поля — `TIMESTAMPTZ`. В коде `new DateTimeImmutable` всегда с явной зоной UTC: `new \DateTimeImmutable('now', new \DateTimeZone('UTC'))`. Никаких `new DateTimeImmutable()` без зоны — он подтянет `date.timezone` из php.ini (Europe/Tallinn) и сломает абсолютные моменты. Пользовательский ввод парсить в зоне юзера и `setTimezone('UTC')` перед сохранением. Конвертация в локальную зону — только на выводе.

### Long-running процессы и Doctrine

В коде, который живёт дольше одного HTTP-запроса (Telegram-бот, Messenger workers, планировщик):

**Никогда** не инжектить `EntityManagerInterface` напрямую и Doctrine-репозитории через конструктор. `ServiceEntityRepository` захватывает EM в `$_em` при старте — после `resetManager()` внутри stale-ссылка.

```php
// ❌ НЕ делать
public function __construct(
    private EntityManagerInterface $em,           // stale после resetManager
    private TaskRepository $tasks,                // internal $_em тоже stale
) {}

// ✅ Правильный паттерн
public function __construct(private ManagerRegistry $doctrine) {}

public function someMethod(): void {
    $em = $this->doctrine->getManager();
    $repo = $em->getRepository(Task::class);
    $task = $repo->find($uuid);
    // мутации
    $em->flush();
}
```

Исключение: обычные HTTP-контроллеры и короткоживущие CLI — там прямой инжект безопасен.

### PHP code style

PSR-1, PSR-12, PER Coding Style. `declare(strict_types=1);` везде. `StudlyCaps` / `camelCase` / `UPPER_SNAKE_CASE`. Короткий синтаксис массивов `[]`. Открывающий `<?php` (короткий запрещён), закрывающий `?>` в чисто-PHP файлах не ставить. LF line endings, 4 пробела, одна пустая строка в конце файла.

---

## Переменные окружения

```
# Порты
NGINX_PORT=8080
ADMINER_PORT=8081
POSTGRES_PORT=5432
REDIS_PORT=6379

# PostgreSQL
POSTGRES_DB=ai_task
POSTGRES_USER=app
POSTGRES_PASSWORD=app
POSTGRES_VERSION=16

# Symfony
APP_ENV=dev
APP_SECRET=changeme
DATABASE_URL="postgresql://app:app@postgres:5432/ai_task?serverVersion=16&charset=utf8"
REDIS_URL="redis://redis:6379"

# Telegram
TELEGRAM_BOT_TOKEN=<from @BotFather>
TELEGRAM_ALLOWED_USER_IDS=<csv of telegram user ids, empty = allow all>

# Anthropic
ANTHROPIC_API_KEY=<from console.anthropic.com>
ANTHROPIC_MODEL_PARSER=claude-haiku-4-5
ANTHROPIC_MODEL_ADVISOR=claude-sonnet-4-6
ANTHROPIC_MODEL_ASSISTANT=claude-sonnet-4-6
```

Все переменные пробрасываются в `php` и `bot` контейнеры через `x-php-service` якорь в `docker-compose.yml` (явные `environment:` записи имеют приоритет над `env_file` — изменения подхватываются при `make up` без пересоздания).

---

## Быстрый старт для новой сессии

```bash
# Поднять окружение
cp .env.example .env                   # заполнить TELEGRAM_BOT_TOKEN, ANTHROPIC_API_KEY
make build
make up

# Войти в php-контейнер
make bash

# Схема + сидер контекстов (идемпотентный)
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed:contexts

# Создать пользователя
php bin/console app:user:create --telegram-id=<твой telegram id> --name="<имя>"

# Запустить бота (долгоживущий)
# Уже запущен как сервис 'bot' в docker-compose;
# make bot-logs — логи, make bot-restart — рестарт

# Слать боту в Telegram команды (/list, /done, /free 2h дома)
# или свободный текст (ассистент разберётся)
```

---

## План следующих шагов

По убыванию приоритета:

1. **Шаг 3 ассистента**: история диалога + Reply-механика. Сейчас каждое сообщение — новая сессия; ассистент не помнит контекст между сообщениями.
2. **Новые tools**: `update_task` (изменить дедлайн/приоритет/контексты), `block_tasks` / `unblock_tasks`, `suggest_free` (интеграция TaskAdvisor в ассистента).
3. **Scheduler** (symfony/scheduler): напоминания по `reminderIntervalMinutes` + `lastRemindedAt`. SnoozeReactivator уже имеет подходящий интерфейс `reactivateExpired(User)` — вынесется в scheduler handler без изменений.
4. **Messenger worker** (`messenger:consume`): асинхронная обработка AI-вызовов (Claude API — несколько секунд, блокирует polling-цикл).
5. **Webhook вместо polling**: когда будет публичный URL / тоннель.
6. **VK-интеграция**: `TaskSource::VK` уже есть в enum'е. Нужен VK Callback API + парсер сообщений.
7. **Soft delete** / audit log для задач — при необходимости.
