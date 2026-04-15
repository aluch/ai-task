# AI Task Agent

Telegram-бот для управления задачами на базе Symfony 7 + Claude API.

## Быстрый старт

```bash
cp .env.example .env   # при необходимости поправить порты/креды
make build
make up
make ps
```

Открыть:
- App (после установки Symfony): http://localhost:8080
- Adminer: http://localhost:8081 (Server: `postgres`, User/Pass/DB — из `.env`)

Вход в php-контейнер и проверка что Symfony работает:

```bash
make bash
php -v
composer -V
php bin/console app:hello   # должно вывести "AI Task Agent is alive — <timestamp>"
```

Остановка и полная очистка:

```bash
make down
make clean   # + удалит volumes
```

## Quick start data

Накатить миграцию, засеять словарь контекстов и создать тестовые данные:

```bash
make bash

# Один раз — создать схему БД
php bin/console doctrine:migrations:migrate --no-interaction

# Один раз — засеять контексты (идемпотентно, можно дёргать сколько угодно)
php bin/console app:seed:contexts

# Тестовый пользователь
php bin/console app:user:create --telegram-id=12345 --name="Test User"
php bin/console app:user:list

# Тестовая задача (по telegram-id или uuid)
php bin/console app:task:create \
    --user-id=12345 \
    --title="Купить билеты на концерт" \
    --deadline="2026-04-20 18:00" \
    --priority=high \
    --contexts=needs_internet,quick

php bin/console app:task:list --user-id=12345

# Закрыть задачу (нужен полный UUID, не префикс)
php bin/console app:task:done 019d9289-3346-71a2-8295-101e73ccb044
php bin/console app:task:list --user-id=12345 --status=done
```

Полный список доступных кодов контекстов и описание модели — в `docs/architecture/data-model.md`.

## Дальше

Symfony 7.4 уже установлен в `app/`. Подробности про стек, бандлы и команды — в `CLAUDE.md`.
