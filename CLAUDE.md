# AI Task Agent

Telegram-бот с интеграцией Claude API для управления задачами.

## Стек

- PHP 8.3 (FPM, Alpine)
- Symfony 7.4
- PostgreSQL 16
- Redis 7
- Nginx 1.27
- Adminer (UI для БД)
- Docker Compose
- Composer 2

## Структура

- `app/` — код Symfony-приложения (document root — `app/public`)
- `docker/php/` — Dockerfile и ini-конфиги PHP (раздельные для FPM и CLI)
- `docker/nginx/` — конфиг Nginx
- `docker-compose.yml` — описание сервисов
- `.env` — переменные окружения (порты, БД, Redis, токены)

## Команды

- `make build` — собрать образы
- `make up` — поднять окружение
- `make down` — остановить
- `make restart` — перезапустить
- `make bash` — войти в php-контейнер (sh)
- `make logs` — хвост логов
- `make ps` — статус контейнеров
- `make clean` — снести всё включая volumes
- `make bot-logs` — логи бота (Telegram polling)
- `make bot-restart` — перезапуск бота
- `make scheduler-logs` — логи scheduler-воркера (напоминания)
- `make scheduler-restart` — перезапуск scheduler
- `make cache-clear` — очистить `var/cache/prod/` (на случай prod-прогонов)
- `make smoke-all` — прогнать все smoke-сценарии (8 штук). Подробности — `docs/testing/smoke.md`
- `make smoke-reset` — удалить тестового пользователя (telegram_id=999999999)
- `make smoke-assistant msg="..."` — разовый прогон Assistant от имени тест-юзера
- `make smoke-parser msg="..."` — DTO-срез TaskParser без сайд-эффектов
- `make smoke-scenario name=<scenario>` — один именованный сценарий

Внутри php-контейнера: `composer`, `php bin/console ...` (после установки Symfony).

## Доступы

- App: http://localhost:${NGINX_PORT} (по умолчанию 8080)
- Adminer: http://localhost:${ADMINER_PORT} (по умолчанию 8081), сервер `postgres`, логин/пароль — из `.env`
- PostgreSQL (с хоста): `localhost:${POSTGRES_PORT}`
- Redis (с хоста): `localhost:${REDIS_PORT}`

## Конвенции

- **PHP**: PSR-1, PSR-12, PER Coding Style.
- `declare(strict_types=1);` во всех PHP-файлах.
- Классы — `StudlyCaps`, методы — `camelCase`, константы — `UPPER_SNAKE_CASE`.
- Короткий синтаксис массивов `[]`.
- Открывающий тег — только `<?php`, закрывающий `?>` в чисто-PHP-файлах не ставить.
- LF line endings, 4 пробела, одна пустая строка в конце файла.
- **Время — всё в UTC.** В БД все datetime-поля — `TIMESTAMPTZ`, в коде создавать `\DateTimeImmutable` всегда с явной зоной UTC: `new \DateTimeImmutable('now', new \DateTimeZone('UTC'))`. Никаких `new \DateTimeImmutable()` без зоны — он подтянет `date.timezone` из php.ini (Europe/Tallinn) и сломает абсолютные моменты. Пользовательский ввод парсить в зоне юзера и `setTimezone('UTC')` перед сохранением. Конвертация в локальную зону — только на выводе (CLI/бот). Подробности — `docs/architecture/data-model.md`, секция «Работа со временем».
- **Long-running контейнеры и Symfony окружение.** `bot` и `scheduler` запускаются в `APP_ENV=dev` + `APP_DEBUG=0`, с автоматическим `cache:clear --env=dev --no-warmup` перед стартом worker'а. Три эшелона защиты от stale-DI, каждый обязателен:
    1. **`APP_ENV=dev`** — при кэш-промахе Symfony пересобирает DI-container с нуля, так что новые сервисы/handler'ы попадают туда без ручной работы.
    2. **`APP_DEBUG=0`** — без debug-режима не подключается `TraceableEventDispatcher`, который в Symfony 7.4 ломает messenger worker loop с ошибкой «Undefined array key `WorkerRunningEvent`». При `APP_DEBUG=1` messenger-воркер падает в каждом тике (баг зафиксирован на 7.4.8). Подмена сервиса через YAML-override не работает — Symfony не даёт заменить аргументы уже-сконфигурированного сервиса.
    3. **`cache:clear` на старте** — страховка от недавнего добавления сервиса: когда debug=0 не пересобирает кэш на mtime-изменениях, старт с чистым кэшем гарантирует актуальный DI. Быстро, ~1s.
    4. **`restart: always`** — `on-failure` НЕ рестартит graceful exit (code 0). `--time-limit=3600` у messenger'а завершает worker корректно каждый час, и с `on-failure` scheduler тихо умирал на 60 минуте, и никто не видел пока не приходил разбор. С `always` гарантированно поднимается.

  **Не переключай scheduler на `prod` или `debug=1` для локальной разработки** — это возвращает один из двух классов багов (см. инциденты 2026-04-22).
- **Long-running процессы и Doctrine.** В коде, который живёт дольше одного HTTP-запроса (Telegram-бот, Messenger воркеры, планировщик), **никогда не инжектить `EntityManagerInterface` напрямую** и **никогда не инжектить Doctrine-репозитории через конструктор**. Репозитории (`ServiceEntityRepository`) захватывают EM в свойство `$_em` при старте — после `$registry->resetManager()` внутри репо остаётся stale ссылка, find() возвращает сущность в identity map старого EM, а flush по текущему EM молча не пишет ничего. Правильный паттерн для любой работы с сущностями:

  ```php
  // В конструкторе — только ManagerRegistry
  public function __construct(private ManagerRegistry $doctrine) {}

  // В методе — всегда свежий EM и свежий repo из него
  $em = $this->doctrine->getManager();
  $repo = $em->getRepository(Task::class);
  $task = $repo->find($uuid);
  // ... мутации ...
  $em->flush();
  ```

  Что **НЕ делать**:

  ```php
  // ❌ Прямой инжект EM — ссылка устаревает после resetManager
  public function __construct(private EntityManagerInterface $em) {}

  // ❌ Прямой инжект репозитория — внутри него $_em устаревает так же
  public function __construct(
      private TaskRepository $tasks,
      private ManagerRegistry $doctrine,
  ) {}

  $task = $this->tasks->find($uuid);           // ← старый EM
  $this->doctrine->getManager()->flush();      // ← новый EM, flush в пустоту
  ```

  Причина: при `DBALException` EM переходит в состояние closed, `$registry->resetManager()` создаёт новый instance, но прямые ссылки (в DI-инжектных EM и репозиториях) остаются на старый. Registry всегда отдаёт живой EM, `$em->getRepository()` — репозиторий, привязанный к этому живому EM.

  Распространяется на: `BotRunner`, все handler'ы бота (которые работают с БД), TaskAdvisor/TaskParser (не работают с БД напрямую, но важно для их будущих расширений), Messenger workers, Scheduler handlers. **Исключение**: read-only операции в HTTP-контроллерах и обычных CLI-командах (короткоживущий процесс, resetManager не случается) — там прямая инжекция EM и репозиториев безопасна.

  Если после обновления встретишь в коде прямой инжект репозитория в конструкторе long-running класса — это кандидат на рефакторинг при следующем касании файла.

## Symfony

Установлено: Symfony **7.4** (skeleton) в `app/`.

Бандлы и пакеты:

- `symfony/framework-bundle` — ядро
- `symfony/orm-pack` (doctrine-bundle, doctrine-migrations-bundle, ORM)
- `symfony/maker-bundle` (dev) — генераторы
- `symfony/console`
- `symfony/messenger` — асинхронные сообщения (DSN по умолчанию `doctrine://default`)
- `symfony/scheduler` — планировщик
- `symfony/http-client` — HTTP-клиент (для Claude API, Telegram API)
- `symfony/uid` — UUID для сущностей
- `symfony/monolog-bundle` — логирование
- `symfony/validator` — валидация
- `nyholm/psr7` — PSR-7 (понадобится для Telegram webhook)
- `nutgram/nutgram` — Telegram Bot API (long polling)

### Локальные переопределения

Файл `app/.env.local` (в .gitignore, не коммитится) задаёт `DATABASE_URL` и прочие переменные под локальное Docker-окружение. Корневой `.env` — для docker-compose, `app/.env.local` — для самого Symfony внутри php-контейнера. Хост БД внутри сети контейнеров — `postgres`, не `localhost`.

### Часто используемые команды

Все команды Symfony/Composer выполняются **внутри php-контейнера**.

```bash
make bash                                 # войти в контейнер (sh)
# затем:
php bin/console list
php bin/console app:hello                 # smoke-test
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
php bin/console make:entity
php bin/console make:command
php bin/console messenger:consume async -vv

# Доменные команды (см. секцию «Модель данных»):
php bin/console app:seed:contexts                                        # идемпотентный сидер контекстов
php bin/console app:user:create --telegram-id=12345 --name="Test User"
php bin/console app:user:list
php bin/console app:task:create --user-id=<uuid|tg-id> --title=... --priority=high --contexts=at_home,quick
php bin/console app:task:list --user-id=<uuid|tg-id> [--status=pending] [--limit=20]
php bin/console app:task:done <task-uuid>
php bin/console app:task:snooze <task-uuid> "+2 hours"   # или tomorrow 09:00, или 2026-04-20 18:00
php bin/console app:task:block <blocked-uuid> <blocker-uuid>
php bin/console app:task:deps <task-uuid>

composer require <vendor/package>
composer update
```

Альтернатива без интерактива (важно: `--user 1000:1000` чтобы файлы не уходили под root):

```bash
docker compose exec --user 1000:1000 php php bin/console <cmd>
docker compose exec --user 1000:1000 php composer <cmd>
```

### Smoke-test

`App\Command\HelloCommand` (`app:hello`) — простая команда, которая печатает «AI Task Agent is alive» и текущее время. Используется для проверки что Symfony правильно загружается, БД доступна, контейнер живой.

## Модель данных

Подробное описание со схемой и обоснованиями — `docs/architecture/data-model.md`. Краткая выжимка:

### Сущности

- **`App\Entity\User`** — пользователь. PK: UUID v7. Поля: `telegramId` (bigint, unique, nullable — привяжется когда подключим бота), `name`, `timezone` (default `Europe/Tallinn`), `createdAt`. Связь `OneToMany` → `Task`.
- **`App\Entity\Task`** — задача. PK: UUID v7. FK на `User` с `ON DELETE CASCADE`. Поля: `title`, `description`, `deadline`, `estimatedMinutes`, `priority`, `status`, `source`, `sourceRef`, `reminderIntervalMinutes`, `lastRemindedAt`, `completedAt`, `snoozedUntil`, `createdAt`/`updatedAt` (через `TimestampableTrait`). Все datetime-поля — TIMESTAMPTZ, хранятся в UTC. Связь `ManyToMany` → `TaskContext` через `task_context_link`. Методы: `markDone()`, `snooze(\DateTimeImmutable $until)`, `addBlocker(Task)`, `isBlocked()`, `getActiveBlockers()`. Связь `ManyToMany(self)` через `task_dependencies` для зависимостей.
- **`App\Entity\TaskContext`** — тег/контекст задачи (`at_home`, `quick`, `needs_internet` и т.д.). Используется для матчинга «задача vs ситуация пользователя» в логике рекомендаций.

### Enum'ы (`App\Enum\`)

- **`TaskPriority`**: `low | medium | high | urgent` (default `medium`)
- **`TaskStatus`**: `pending | in_progress | done | cancelled | snoozed` (default `pending`)
- **`TaskSource`**: `manual | ai_parsed | vk | telegram` — откуда задача попала в систему (default `manual`)

Все enum'ы — backed string, привязаны через нативный Doctrine ORM 3 `enumType:` атрибут. В БД хранятся как `VARCHAR(16)`.

### Трейты

- `App\Entity\Trait\CreatedAtTrait` — только `createdAt` + `#[PrePersist]` (для `User`).
- `App\Entity\Trait\TimestampableTrait` — `createdAt` + `updatedAt` + `#[PrePersist]`/`#[PreUpdate]` (для `Task`).

Сущности, использующие трейт, должны быть помечены `#[ORM\HasLifecycleCallbacks]`, иначе коллбеки не вызываются.

### UUID

Все PK — UUID v7 (`Symfony\Component\Uid\Uuid::v7()`). В Postgres хранятся в нативном типе `UUID` (через `Symfony\Bridge\Doctrine\Types\UuidType`, зарегистрирован в `config/packages/doctrine.yaml`). UUID v7 сортируется по времени создания, что хорошо ложится на B-tree индекс PK.

## Telegram

Библиотека: **nutgram/nutgram** v4. Бот работает в режиме long polling. Подробная архитектура — `docs/architecture/telegram.md`.

### Компоненты

- `App\Telegram\BotRunner` — создаёт Nutgram в runtime, регистрирует handlers, запускает polling. Nutgram создаётся вручную (не через DI) — его конструктор бросает исключение при пустом токене.
- `App\Telegram\HandlerRegistry` — регистрирует middleware и handlers на Nutgram.
- `App\Telegram\Handler\` — handler-классы (invokable, один класс = одна команда).
- `App\Telegram\Middleware\WhitelistMiddleware` — фильтр по `TELEGRAM_ALLOWED_USER_IDS`.
- `App\Service\TelegramUserResolver` — find-or-create User по telegram_id.
- `App\Service\RelativeTimeParser` — парсинг относительных и абсолютных форматов времени. Используется и в CLI (`TaskSnoozeCommand`), и в боте (`SnoozeHandler`).
- `App\Service\PaginationStore` — Redis-хранилище состояний пагинации (state для inline-меню с листанием + waiting_search для кнопки 🔍 Поиск). TTL сессии 1 час, TTL waiting_search 2 минуты.
- `App\Service\UserActivityTracker::recordMessage(User)` — обновляет `User.lastMessageAt`. Вызывается из middleware `HandlerRegistry` на каждом update'е. Нужно Scheduler'у чтобы не слать напоминания во время активного диалога.
- `App\Notification\TelegramNotifier::sendMessage(chatId, text, replyMarkup?, parseMode?)` — тонкая HTTP-обёртка над Telegram Bot API (без Nutgram). Для side-channel отправок из Scheduler/Messenger workers.
- `App\Notification\ReminderSender` — три типа уведомлений (все с quiet hours, у каждого своя политика по recently_active):
  - `sendDeadlineReminder(Task)` (Тип А) — напоминание о приближающемся дедлайне. Если `remindBeforeDeadlineMinutes < 5`, фильтр recently_active пропускается (пользователь сам просил короткое).
  - `sendPeriodicReminder(Task)` (Тип Б) — периодический «пинок» по задаче без дедлайна, каждые `reminderIntervalMinutes` минут (для IN_PROGRESS — x2). Обновляет `lastRemindedAt` как скользящее окно.
  - `sendSnoozeWakeup(Task)` (Тип В) — уведомление «задача снова активна» по истечении `snoozedUntil`, с последующей реактивацией (PENDING). Recently_active не применяется. При quiet hours задача остаётся SNOOZED до следующего тика.

  Все возвращают `SendResult`: SENT / SKIPPED_QUIET_HOURS / SKIPPED_RECENTLY_ACTIVE / SKIPPED_NO_CHAT_ID / FAILED.
- `App\Scheduler\ReminderSchedule` (#[AsSchedule('reminders')]) — раз в минуту диспатчит четыре recurring message'а через Messenger на транспорт `scheduler_reminders` (doctrine://default): `CheckDeadlineRemindersMessage`, `CheckPeriodicRemindersMessage`, `CheckSnoozeWakeupsMessage`, `CheckSingleRemindersMessage`.
- `App\MessageHandler\CheckDeadlineRemindersHandler` / `CheckPeriodicRemindersHandler` / `CheckSnoozeWakeupsHandler` / `CheckSingleRemindersHandler` — каждый выбирает своих кандидатов через `TaskRepository` (`findDeadlineReminderCandidates`, `findPeriodicReminderCandidates`, `findSnoozeWakeupCandidates`, `findSingleReminderCandidates`) и прогоняет через `ReminderSender`.
- **Пробуждение SNOOZED — только через Scheduler**. Бывший `SnoozeReactivator` удалён, `TaskRepository::findForUser` больше не пытается lazy-reactivate. Пользователь получает явное уведомление, прежде чем задача появится в списках — ценой латенса до 1 минуты.
- `App\Telegram\Paginator` — сборка клавиатур пагинации (task picker с «← Назад / Стр. N/M / Далее → / 🔍 Поиск / ✖ Закрыть» + list-keyboard только с навигацией).
- `App\Telegram\SearchDispatcher` — роутинг поискового текста (после 🔍) в нужный handler по action'у сохранённой сессии.

### Команды бота

- `/start` — регистрация и приветствие
- `/help` — справка по командам
- `/list` — открытые задачи (до 10) с дедлайнами в зоне юзера
- `/done <id>` — пометить задачу выполненной (первые 8+ символов UUID). Уведомляет о разблокированных задачах.
- `/snooze <id> <когда>` — отложить задачу
- `/block <task> <blocker>` — задача task заблокирована blocker'ом (проверка циклов)
- `/unblock <task> <blocker>` — убрать зависимость
- `/deps <id>` — показать зависимости задачи
- `/free <время> [контекст]` — AI подбирает задачи под свободное время и контекст (главная фича). Inline-кнопки: Беру/Другие/Не сейчас. Состояние в Redis.
- (свободный текст) — AI-парсинг задачи через Claude (title, deadline, priority, contexts)

### Сервис в docker-compose

```yaml
bot:
  command: php bin/console app:bot:run -vv
  restart: on-failure   # exit 0 (нет токена) — не перезапускает
```

## AI

Подробная архитектура — `docs/architecture/ai-integration.md`.

### Компоненты

- `App\AI\ClaudeClient` — обёртка вокруг Symfony HttpClient для Anthropic Messages API. Классифицирует ошибки: `ClaudeClientException` (4xx), `ClaudeTransientException` (5xx/сеть), `ClaudeRateLimitException` (429). Логирует каждый вызов: модель, tokens, elapsed.
- `App\AI\TaskParser` — превращает свободный текст в `ParsedTaskDTO`. System prompt содержит текущее время пользователя, его timezone и список контекстов из БД. Ответ — JSON, парсится с fallback.
- `App\AI\TaskAdvisor` — подбирает оптимальный набор задач под свободное время и контекст (для команды `/free`). Отличается от парсера: не извлечение, а рассуждение (приоритеты, маршруты, группировка). Используется Sonnet вместо Haiku. Подробности — `docs/architecture/task-advisor.md`.
- `App\AI\Assistant` — AI-ассистент через tool calling. Обрабатывает свободный текст в Telegram через `AssistantHandler`, выбирает нужный tool на основе намерения пользователя, исполняет и отвечает по-человечески. Tool use loop до 5 итераций, retry на rate-limit/5xx от Anthropic. Подробности — `docs/architecture/assistant.md`.

  Доступные tools (11 штук, все автоконфигурируются по тегу `app.assistant_tool`):
  - `create_task` — с защитой от дубликатов (корни ≥50% совпадают → success=false), обход через `force=true`.
  - `list_tasks` — фильтры по статусу + query.
  - `search_tasks_by_title` — fuzzy-поиск с морфо-стеммингом.
  - `update_task` — любое поле задачи; при смене дедлайна/remind_before сбрасывает `deadline_reminder_sent_at`.
  - `mark_task_done`, `snooze_task` (ставит `respect_quiet_hours=false` — user-chosen time).
  - `add_reminder_to_task` — remind_before_deadline_minutes или reminder_interval_minutes (клампится к 60). Тоже `respect_quiet_hours=false`.
  - `add_single_reminder` — одноразовое на конкретный момент (Тип Г). Задача остаётся активной, не snooze.
  - `block_task` / `unblock_task` — с проверкой циклов.
  - `suggest_tasks` — вызов TaskAdvisor (аналог /free, но только текст без кнопок).

  После любых изменений в Ассистенте или tools — `make smoke-all` (14 сценариев). В `smoke:all` есть `sleep(8)` между assistant-сценариями, чтобы не упираться в 30k TPM Anthropic rate-limit.
- `App\AI\DTO\ClaudeResponse` — DTO ответа Claude API.
- `App\AI\DTO\ParsedTaskDTO` — DTO разобранной задачи (title, description, deadline, priority, contextCodes, parserNotes).
- `App\AI\DTO\TaskSuggestionDTO` + `SuggestedTask` — DTO подборки задач (suggestions, reasoning, totalEstimatedMinutes, noMatchReason).
- `App\Service\RelativeTimeParser::parseToMinutes(string $input): int` — парсит длительность (30m, 1h, 1.5h, 90m, 2ч, 45м, 120). Используется в `/free` и `/snooze`.
- `App\Service\FreeSuggestionStore` — Redis-хранилище состояния предложения для `/free` (task_ids, excluded_ids, reroll_count). TTL 1 час. В callback_data не помещаются UUID, поэтому state в Redis + короткий ключ в callback.

### Модели

| Use case | Модель | Переменная |
|---|---|---|
| Парсинг задач | `claude-haiku-4-5` | `ANTHROPIC_MODEL_PARSER` |
| Подбор задач (`/free`) | `claude-sonnet-4-6` | `ANTHROPIC_MODEL_ADVISOR` |
| Ассистент (свободный текст) | `claude-sonnet-4-6` | `ANTHROPIC_MODEL_ASSISTANT` |

Haiku выбрана для парсинга: достаточно умна для структурного извлечения, значительно дешевле и быстрее Opus/Sonnet. Для подбора нужна Sonnet — несколько конкурирующих критериев (приоритет, контекст, маршрут, время) Haiku путает. Переключение через `.env` без передеплоя.

### Переменные окружения

- `ANTHROPIC_API_KEY` — API key от Anthropic (обязателен для AI-функций)
- `ANTHROPIC_MODEL_PARSER` — модель парсера (default `claude-haiku-4-5`)
- `ANTHROPIC_MODEL_ADVISOR` — модель advisor'а (default `claude-sonnet-4-6`)
- `ANTHROPIC_MODEL_ASSISTANT` — модель ассистента (default `claude-sonnet-4-6`)

### Команды vs свободный текст

- **Команды** (`/list`, `/done`, `/free`, `/snooze` и т.д.) — быстрый путь с inline-кнопками или аргументами. Детерминированные, предсказуемые.
- **Свободный текст** идёт в Assistant, который через tool calling сам решает что сделать (создать задачу, показать список, пометить выполненной, отложить). Для диалогового UX.

Правило: если есть готовая команда под задачу — используем её. Если пользователь пишет человеческим языком — ассистент разбирается.

## Smoke-тесты

`make smoke-all` прогоняет 8 сценариев reminder-пайплайна за ~2 секунды
без реальных Telegram-запросов — используется InMemoryTelegramNotifier и
FrozenClock. **После любых изменений в ReminderSender, handler'ах, правилах
quiet hours / recently_active, reminder-репо методах — обязательный прогон.**

Каждый сценарий должен быть ✅. Если что-то падает — это реальный баг,
не смиряйся, разберись. Подробности: `docs/testing/smoke.md`.

Тестируемые абстракции:

- **`App\Clock\Clock`** (interface) — `SystemClock` в проде, `FrozenClock`
  в smoke-сценариях. Инжектится в reminder pipeline: `ReminderSender`,
  три `Check*RemindersHandler`, `UserActivityTracker`. Остальные сервисы
  (TaskParser, handlers бота и т.д.) продолжают использовать `new DateTimeImmutable`
  — это терпимо, основная логика времени в reminder-пайплайне. Поле
  `$clock` в этих сервисах **не `readonly`** — SmokeHarness подменяет его
  через reflection (PHP 8.3 запрещает менять readonly даже через reflection).

- **`App\Notification\TelegramNotifierInterface`** — прод-реализация
  `TelegramNotifier` (HTTP POST в Bot API), smoke-реализация
  `InMemoryTelegramNotifier` (накопление в массиве). `TelegramNotifier`
  знает про in-memory режим через метод `useInMemory(sink)` — SmokeHarness
  вызывает его при конструировании, с тех пор все отправки в тесте
  идут в in-memory.

- **`App\Smoke\SmokeHarness`** — фасад для smoke-команд: тестовый юзер
  (tg_id=999999999), подмена notifier'а и clock'а, reset БД. НЕ используй
  его в прод-коде.

## Следующие шаги

1. Добавить worker-контейнер `messenger:consume` на том же php-образе.
2. Перейти на webhook (когда будет публичный URL/tunneling).
