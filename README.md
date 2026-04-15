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

## Дальше

Symfony 7.4 уже установлен в `app/`. Подробности про стек, бандлы и команды — в `CLAUDE.md`.
