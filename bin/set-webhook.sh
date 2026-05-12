#!/usr/bin/env bash
# Устанавливает Telegram webhook на текущий DOMAIN. Идемпотентен —
# повторный вызов перезаписывает конфигурацию вебхука.
#
# Использует TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET, DOMAIN из .env.
# Можно переопределить URL переменной TELEGRAM_WEBHOOK_URL (для ngrok-тестов).

set -euo pipefail

cd "$(dirname "$0")/.."

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

: "${TELEGRAM_BOT_TOKEN:?TELEGRAM_BOT_TOKEN не задан в .env}"
: "${TELEGRAM_WEBHOOK_SECRET:?TELEGRAM_WEBHOOK_SECRET не задан в .env}"

if [ -n "${TELEGRAM_WEBHOOK_URL:-}" ]; then
    URL="${TELEGRAM_WEBHOOK_URL%/}/api/telegram/webhook/${TELEGRAM_WEBHOOK_SECRET}"
else
    : "${DOMAIN:?DOMAIN не задан в .env (или передай TELEGRAM_WEBHOOK_URL)}"
    URL="https://${DOMAIN}/api/telegram/webhook/${TELEGRAM_WEBHOOK_SECRET}"
fi

echo "Setting webhook to: ${URL}"

# allowed_updates: важно держать pre_checkout_query и shipping_query —
# без них Telegram Payments молча таймаутит платёж (BOT_PRECHECKOUT_TIMEOUT).
# По умолчанию Telegram не шлёт эти типы в webhook, даже если у бота есть
# onPreCheckoutQuery handler. Этот список перезаписывается при каждом
# деплое через bin/deploy.sh — ручной фикс через API не переживёт следующий
# rollout, источник истины тут.
curl -fsS -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook" \
    -H "Content-Type: application/json" \
    -d "$(printf '{"url":"%s","secret_token":"%s","drop_pending_updates":true,"allowed_updates":["message","edited_message","callback_query","pre_checkout_query","shipping_query"]}' \
        "$URL" "$TELEGRAM_WEBHOOK_SECRET")"
echo
echo "✅ Webhook set"
