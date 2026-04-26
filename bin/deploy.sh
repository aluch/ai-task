#!/usr/bin/env bash
# Production deploy. Запускать на VPS из корня репозитория.
#
# Шаги:
#   1. git pull
#   2. сборка prod-образа (composer --no-dev, opcache warmed, prod cache)
#   3. миграции БД (через одноразовый --rm контейнер)
#   4. up -d (рестарт долгоживущих сервисов: php, scheduler, caddy)
#   5. (re)set webhook на стороне Telegram
#
# Идемпотентен — повторный запуск не сломает уже задеплоенное.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "❌ .env не найден. Скопируй .env.example → .env и заполни." >&2
    exit 1
fi

echo "==> git pull"
git pull --ff-only origin main

echo "==> docker compose build (prod)"
docker compose -f docker-compose.prod.yml build

echo "==> doctrine migrations"
docker compose -f docker-compose.prod.yml run --rm php \
    php bin/console doctrine:migrations:migrate --no-interaction --env=prod

echo "==> seed contexts (idempotent)"
docker compose -f docker-compose.prod.yml run --rm php \
    php bin/console app:seed:contexts --env=prod || true

echo "==> up -d"
docker compose -f docker-compose.prod.yml up -d

echo "==> set webhook"
"$(dirname "$0")/set-webhook.sh"

echo "✅ Deploy complete"
