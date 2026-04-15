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
- **`App\Entity\Task`** — задача. PK: UUID v7. FK на `User` с `ON DELETE CASCADE`. Поля: `title`, `description`, `deadline`, `estimatedMinutes`, `priority`, `status`, `source`, `sourceRef`, `reminderIntervalMinutes`, `lastRemindedAt`, `completedAt`, `createdAt`/`updatedAt` (через `TimestampableTrait`). Связь `ManyToMany` → `TaskContext` через `task_context_link`.
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

## Следующие шаги

1. Подключить интеграцию с Telegram (webhook + long-polling вариант).
2. Подключить Claude API через `symfony/http-client` + сервис-обёртку (создание задач из текста — `source = ai_parsed`).
3. Добавить worker-контейнер `messenger:consume` на том же php-образе.
4. Реализовать reminder-планировщик через `symfony/scheduler` (`reminderIntervalMinutes` + `lastRemindedAt`).
