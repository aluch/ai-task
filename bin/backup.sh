#!/usr/bin/env bash
# Ежедневный pg_dump → Yandex Object Storage (S3-совместимый API).
# Хранение: 30 дней (старые удаляются автоматически).
# Уведомление в Telegram только при ошибке + раз в неделю об успехе
# (чтобы не утопить чат «всё ок»-сообщениями каждый день).

set -euo pipefail

cd "$(dirname "$0")/.."

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

: "${YC_ACCESS_KEY_ID:?YC_ACCESS_KEY_ID не задан в .env}"
: "${YC_SECRET_ACCESS_KEY:?YC_SECRET_ACCESS_KEY не задан в .env}"
: "${YC_BUCKET:?YC_BUCKET не задан в .env}"
: "${POSTGRES_USER:?POSTGRES_USER не задан в .env}"
: "${POSTGRES_DB:?POSTGRES_DB не задан в .env}"

# Yandex Object Storage — S3-совместимый, endpoint и регион зашиты.
YC_ENDPOINT="https://storage.yandexcloud.net"
YC_REGION="ru-central1"

TS=$(date -u +'%Y%m%d-%H%M%S')
DUMP_FILE="/tmp/pomni-backup-${TS}.sql.gz"

# Уведомление об ошибке через trap (срабатывает при любом не-нулевом exit).
trap 'on_error $?' EXIT

on_error() {
    local code=$?
    if [ $code -ne 0 ] && [ -n "${TG_NOTIFY_BOT_TOKEN:-}" ] && [ -n "${TG_NOTIFY_CHAT_ID:-}" ]; then
        curl -fsS -X POST \
            "https://api.telegram.org/bot${TG_NOTIFY_BOT_TOKEN}/sendMessage" \
            --data-urlencode "chat_id=${TG_NOTIFY_CHAT_ID}" \
            --data-urlencode "text=❌ Бэкап Postgres упал, exit=${code}. Проверь ~/backup.log на VPS." \
            > /dev/null || true
    fi
    rm -f "${DUMP_FILE}" 2>/dev/null || true
}

echo "==> pg_dump → ${DUMP_FILE}"
docker compose -f docker-compose.prod.yml exec -T postgres \
    pg_dump -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" --clean --if-exists \
    | gzip -9 > "${DUMP_FILE}"

SIZE=$(stat -c %s "${DUMP_FILE}")
echo "==> dump size: ${SIZE} bytes"

# Sanity-check: gzip-пустой файл ~20 байт; пустая БД даёт ~1KB.
# <1KB — точно что-то не так (pg_dump молча упал).
if [ "${SIZE}" -lt 1024 ]; then
    echo "❌ dump suspiciously small (${SIZE} bytes)"
    exit 1
fi

echo "==> upload to Yandex Object Storage"
AWS_ACCESS_KEY_ID="${YC_ACCESS_KEY_ID}" \
AWS_SECRET_ACCESS_KEY="${YC_SECRET_ACCESS_KEY}" \
aws s3 cp "${DUMP_FILE}" "s3://${YC_BUCKET}/postgres/$(basename "${DUMP_FILE}")" \
    --endpoint-url "${YC_ENDPOINT}" \
    --region "${YC_REGION}"

rm -f "${DUMP_FILE}"

# Удаляем дампы старше 30 дней. CUTOFF — YYYYMMDD на 30 дней назад
# (lexicographic compare работает с этим форматом).
echo "==> prune old backups (>30 days)"
CUTOFF=$(date -u -d '30 days ago' +'%Y%m%d')

AWS_ACCESS_KEY_ID="${YC_ACCESS_KEY_ID}" \
AWS_SECRET_ACCESS_KEY="${YC_SECRET_ACCESS_KEY}" \
aws s3 ls "s3://${YC_BUCKET}/postgres/" \
    --endpoint-url "${YC_ENDPOINT}" \
    --region "${YC_REGION}" \
    | awk '{print $4}' \
    | grep -E '^pomni-backup-[0-9]{8}' \
    | while read -r key; do
        BACKUP_DATE=$(echo "$key" | grep -oE '[0-9]{8}' | head -1)
        if [ "$BACKUP_DATE" -lt "$CUTOFF" ]; then
            echo "  removing old: $key"
            AWS_ACCESS_KEY_ID="${YC_ACCESS_KEY_ID}" \
            AWS_SECRET_ACCESS_KEY="${YC_SECRET_ACCESS_KEY}" \
            aws s3 rm "s3://${YC_BUCKET}/postgres/${key}" \
                --endpoint-url "${YC_ENDPOINT}" \
                --region "${YC_REGION}" || true
        fi
    done

echo "✅ Backup complete: ${TS}"

# Раз в неделю (воскресенье UTC) — уведомление об успехе с размером.
# Остальные дни — молча, чтобы не флудить чат.
if [ "$(date -u +%u)" = "7" ] && [ -n "${TG_NOTIFY_BOT_TOKEN:-}" ] && [ -n "${TG_NOTIFY_CHAT_ID:-}" ]; then
    SIZE_HUMAN=$(numfmt --to=iec-i --suffix=B "${SIZE}" 2>/dev/null || echo "${SIZE}B")
    curl -fsS -X POST \
        "https://api.telegram.org/bot${TG_NOTIFY_BOT_TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${TG_NOTIFY_CHAT_ID}" \
        --data-urlencode "text=✅ Бэкап жив. Последний дамп ${SIZE_HUMAN}, ${TS}." \
        > /dev/null || true
fi

trap - EXIT
