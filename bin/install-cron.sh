#!/usr/bin/env bash
# Установить cron-задачу для ежедневного backup.sh. Запустить один раз
# на VPS под пользователем deploy.
#
# Идемпотентен — повторный запуск не дублирует строку.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CRON_LINE="0 4 * * * cd ${REPO_DIR} && /bin/bash bin/backup.sh >> ${HOME}/backup.log 2>&1"

# Достаём текущий crontab (или пустоту), вычищаем нашу строку (если была),
# добавляем свежую, ставим обратно.
( crontab -l 2>/dev/null | grep -v 'ai-task/bin/backup.sh' ; echo "${CRON_LINE}" ) | crontab -

echo "✅ Cron установлен. Будет запускаться ежедневно в 04:00 UTC."
echo "Проверить: crontab -l"
echo "Логи:      tail -f ${HOME}/backup.log"
echo "Ручной:    bash bin/backup.sh"
