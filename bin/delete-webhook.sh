#!/usr/bin/env bash
# Удаляет Telegram webhook — возвращает бот в режим polling.
# Используется для отладки и переключения с webhook обратно на long polling.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

: "${TELEGRAM_BOT_TOKEN:?TELEGRAM_BOT_TOKEN не задан в .env}"

curl -fsS -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/deleteWebhook?drop_pending_updates=true"
echo
echo "✅ Webhook deleted (polling mode again)"
