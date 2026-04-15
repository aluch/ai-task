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

Вход в php-контейнер:

```bash
make bash
php -v
composer -V
```

Остановка и полная очистка:

```bash
make down
make clean   # + удалит volumes
```

## Дальше

В `app/` устанавливается Symfony 7 skeleton — см. `CLAUDE.md`.
