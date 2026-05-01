# Backup & Recovery

Ежедневный pg_dump → Yandex Object Storage. Хранение 30 дней, старые удаляются автоматически. Уведомление в Telegram только при ошибке + раз в неделю об успехе.

## Установка на VPS — один раз

### 1. Yandex Cloud + bucket

1. https://cloud.yandex.ru/services/storage
2. Создать сервисный аккаунт `pomni-backup` с ролью `storage.editor`.
3. У этого аккаунта создать **статический ключ доступа** — сохранить ID и Секрет (показываются один раз).
4. Создать bucket `pomni-backups`:
   - Класс хранилища: **Холодное** (дешевле для редкого чтения)
   - Доступ: Ограниченный

### 2. Прописать в `.env` на сервере

```env
YC_ACCESS_KEY_ID=YCAJExxxxxxxxxxxxxxxxx
YC_SECRET_ACCESS_KEY=YCxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
YC_BUCKET=pomni-backups
```

Endpoint и регион зашиты в `bin/backup.sh` (это константы Yandex Cloud — `https://storage.yandexcloud.net`, `ru-central1`).

### 3. AWS CLI и cron

В Ubuntu 24.04 пакет `awscli` удалён из репозиториев — `apt install awscli`
вернёт `Package 'awscli' has no installation candidate`. Ставим AWS CLI v2
от Amazon (официальный installer):

```bash
sudo apt install -y unzip
curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o awscliv2.zip
unzip awscliv2.zip
sudo ./aws/install
rm -rf awscliv2.zip aws/
aws --version             # должен показать aws-cli/2.x

bash bin/backup.sh         # тестовый запуск, должен залить в bucket
bash bin/install-cron.sh   # ежедневно 04:00 UTC
crontab -l                 # проверить что строка добавилась
```

`backup.sh` идемпотентен — повторный запуск создаёт новый файл с актуальным timestamp. `install-cron.sh` тоже идемпотентен — повторный запуск не дублирует строку.

## Расписание

- **04:00 UTC** (07:00 Europe/Tallinn) — pg_dump → bucket.
- **Воскресенье 04:00 UTC** — дополнительно telegram-уведомление об успехе с размером дампа («бэкап жив»). Остальные дни — молча.
- При любой ошибке — telegram-уведомление с exit code и подсказкой смотреть `~/backup.log`.

## Восстановление

### Скачать конкретный бэкап

```bash
source .env
AWS_ACCESS_KEY_ID="$YC_ACCESS_KEY_ID" \
AWS_SECRET_ACCESS_KEY="$YC_SECRET_ACCESS_KEY" \
aws s3 cp "s3://${YC_BUCKET}/postgres/pomni-backup-20260501-040000.sql.gz" . \
    --endpoint-url https://storage.yandexcloud.net \
    --region ru-central1
```

### Безопасное восстановление (через временную БД)

`pg_dump --clean --if-exists` означает: при импорте сначала удалит существующие таблицы и потом восстановит. **Это разрушительно для текущих данных.** Безопасный путь — отдельная БД для проверки:

```bash
# Создать временную БД
docker compose -f docker-compose.prod.yml exec postgres \
    psql -U app -c "CREATE DATABASE ai_task_restore_test;"

# Восстановить туда
gunzip -c pomni-backup-20260501-040000.sql.gz | \
    docker compose -f docker-compose.prod.yml exec -T postgres \
        psql -U app -d ai_task_restore_test

# Проверить через psql / Adminer что данные на месте.
# Если ок — переименовать (или скопировать выборочные данные).
```

### Прямое восстановление в основную (если уверен)

```bash
docker compose -f docker-compose.prod.yml stop scheduler bot php  # остановить writers
gunzip -c pomni-backup-20260501-040000.sql.gz | \
    docker compose -f docker-compose.prod.yml exec -T postgres \
        psql -U app -d ai_task
docker compose -f docker-compose.prod.yml start scheduler bot php
```

## Что НЕ покрыто

- **Бэкапы Redis** — там история диалогов Ассистента (`conversation:<uuid>`), pending actions, история состояний пагинации. Потеря не критична: пользователь напишет «отложи её на завтра» заново. Если станет важно — добавить отдельный backup по аналогии (`redis-cli SAVE` + копирование `dump.rdb`).
- **Точечный restore** (только определённые таблицы) — overkill пока. Если понадобится — `pg_restore --table=tasks` из несжатого custom-формата вместо plain SQL.
- **Off-site копия** (вторая локация) — Yandex держит данные в `ru-central1`. Для критичных данных можно настроить cross-region replication в S3, но для personal-tier бота избыточно.
