#!/usr/bin/env bash
# Production deploy. Запускать на VPS из корня репозитория.
#
# Шаги:
#   1. git pull
#   2. сборка prod-образа (composer --no-dev, opcache warmed, prod cache)
#   3. миграции БД (через одноразовый --rm контейнер)
#   4. up -d (рестарт долгоживущих сервисов: php, scheduler, caddy)
#   5. (re)set webhook на стороне Telegram
#   6. healthcheck-poll: ждём 200 от /health в течение 60s
#
# Идемпотентен — повторный запуск не сломает уже задеплоенное.
# Запускается из GitHub Actions (без TTY) — все docker-команды идут с -T.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "❌ .env не найден. Скопируй .env.example → .env и заполни." >&2
    exit 1
fi

# DOMAIN нужен для финального healthcheck'а (curl https://${DOMAIN}/health).
# shellcheck disable=SC1091
set -a
source .env
set +a

echo "==> git pull"
git pull --ff-only origin main

echo "==> docker compose build (prod)"
docker compose -f docker-compose.prod.yml build

# -T отключает TTY-аллокацию для одноразовых команд. Без него SSH-сессия
# из CI бывает дважды-привязана к псевдо-TTY и валится с ошибкой
# «the input device is not a TTY».
echo "==> doctrine migrations"
docker compose -f docker-compose.prod.yml run --rm -T php \
    php bin/console doctrine:migrations:migrate --no-interaction --env=prod

echo "==> seed contexts (idempotent)"
docker compose -f docker-compose.prod.yml run --rm -T php \
    php bin/console app:seed:contexts --env=prod || true

echo "==> up -d"
docker compose -f docker-compose.prod.yml up -d

# Валидация: реально ли критичные переменные дошли до php-контейнера.
# Раньше был баг — переменная задана в .env, но не пробрасывалась через
# environment в compose, и она тихо была пустой в контейнере (см.
# инцидент с ADMIN_TELEGRAM_ID, fix(prod): pass ADMIN_TELEGRAM_ID...).
echo "==> validating env in container"
for var in TELEGRAM_BOT_TOKEN ANTHROPIC_API_KEY ADMIN_TELEGRAM_ID DATABASE_URL DOMAIN; do
    val=$(docker compose -f docker-compose.prod.yml exec -T php sh -c "printf '%s' \"\${$var}\"" 2>/dev/null || true)
    if [ -z "$val" ]; then
        echo "❌ $var is empty in php container — проверь .env и docker-compose.prod.yml" >&2
        exit 1
    fi
    echo "  ✅ $var is set"
done

echo "==> set webhook"
"$(dirname "$0")/set-webhook.sh"

echo "==> waiting for healthcheck"
# 12 попыток × 5s = 60s окно. Caddy с warmed image обычно готов через
# ~15-20s (TLS-handshake к Let's Encrypt при первом старте дольше).
for i in $(seq 1 12); do
    if curl -fsS -o /dev/null "https://${DOMAIN}/health"; then
        echo "✅ Deploy complete (healthcheck passed on attempt ${i})"
        exit 0
    fi
    echo "  attempt ${i}/12 failed, sleep 5s"
    sleep 5
done

echo "❌ Healthcheck failed after 60s — see https://${DOMAIN}/health and docker logs" >&2
exit 1
