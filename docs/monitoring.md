# Мониторинг

Базовый набор страховки прода: внешний пинг + scheduler-heartbeat в `/health` + ротация Docker logs.

## /health endpoint

`GET https://${DOMAIN}/health` возвращает JSON со статусом каждой подсистемы и HTTP 200/503.

```json
{
  "status": "ok",
  "checks": {
    "db": "ok",
    "redis": "ok",
    "scheduler": "ok"
  }
}
```

Что детектит:
- `db: fail: ...` — Postgres недоступен (SELECT 1 упал) → 503.
- `redis: fail: ...` — Redis недоступен (PING упал) → 503.
- `scheduler: stale` — `scheduler_heartbeat.last_tick_at` старше 3 минут → 503. Scheduler пишет heartbeat на каждом из 4 reminder-tick'ов (раз в минуту). Если 3 минуты молчит — worker упал, повис, или БД недоступна.

Без AI-вызовов, обычно <50мс. Подходит для частых пингов.

## Scheduler heartbeat — как работает

- Таблица `scheduler_heartbeat` (singleton, id=1, миграция `Version20260501000000`).
- `App\Service\HeartbeatTracker::recordTick($now)` — `UPDATE … WHERE id = 1` (DBAL напрямую, без ORM).
- `recordTick` вызывается в начале `__invoke()` всех 4 handler'ов: `CheckDeadline`, `CheckPeriodic`, `CheckSnoozeWakeups`, `CheckSingle`. Любой из них фиксирует «scheduler жив». Вызов **до** `try/catch` — transient ошибки tick'а не делают scheduler stale.
- `HealthController` сравнивает `last_tick_at` с now, threshold 180 сек.

Failure modes:
- Worker упал (OOM, crash, не рестартнулся) → heartbeat не пишется → 503.
- Scheduler не получает сообщения (messenger transport сломан) → heartbeat не пишется → 503.
- Postgres недоступен → `recordTick` падает (но и сам `db` check тоже упадёт) → 503.

## Uptime Robot — внешний пинг

Бесплатный сервис, проверяет /health каждые 5 минут. При HTTP не-200 или таймауте — алерт в Telegram.

### Настройка (один раз)

1. Регистрация на https://uptimerobot.com (бесплатно — 50 мониторов, 5-минутный интервал).
2. **Add New Monitor**:
   - Type: HTTPS
   - Name: Pomni Production
   - URL: `https://tasks.luchnikov.ru/health`
   - Interval: 5 minutes
   - Keyword Type: `exists`
   - Keyword: `"status":"ok"` (без пробела — JSON-формат от Symfony JsonResponse)
3. **Alert Contacts → Add → Telegram**:
   - Используй того же `@pomni_deploy_bot` что и для CI-уведомлений (он уже умеет писать админу).
   - Bot token: см. GitHub Secret `TG_NOTIFY_BOT_TOKEN`.
   - Chat ID: твой Telegram ID.
4. Save.

### Что детектится

- 503 от приложения (любая из подсистем `down`).
- Connection timeout / connection refused (VPS упал, Docker встал).
- DNS не резолвится (DNS-провайдер сломал запись).
- TLS expired (в нашем случае Caddy перевыпускает автоматически, но если ломалась логика автообновления — увидим).

### Что НЕ детектится

- Логические баги (бот отвечает, но обрабатывает неправильно).
- Медленные ответы <таймаута Uptime Robot.
- Истечение Anthropic API key (Assistant вернёт ошибку юзеру, /health про это не знает).
- Telegram заблокировал бота за спам (webhook-запросы перестают приходить — но /health здоров).

Это нормально для базовой страховки. Health-чеки следующего уровня (canary-проверка Assistant с тестовым запросом, проверка валидности Anthropic ключа) — отдельный этап, если решим что нужно.

## Ротация Docker logs

В `docker-compose.prod.yml` через YAML-anchor `x-logging`:

```yaml
x-logging: &default-logging
  driver: json-file
  options:
    max-size: "50m"
    max-file: "3"
    compress: "true"
```

Применяется ко всем сервисам (`caddy`, `php`, `scheduler`, `postgres`, `redis` через `<<: *php-service`). Каждый контейнер хранит до 3 файлов по 50MB, старые сжимаются. Итог — ~150MB на контейнер max.

Проверить путь к логам конкретного контейнера:
```bash
docker inspect ai-task-php-prod | grep LogPath
# → /var/lib/docker/containers/<id>/<id>-json.log
```

Просмотр в нормальном режиме — через `docker compose logs`:
```bash
docker compose -f docker-compose.prod.yml logs --tail=100 -f php
docker compose -f docker-compose.prod.yml logs --since=1h scheduler
```

## Что делать при алёрте

1. **Uptime Robot говорит down** → `curl https://${DOMAIN}/health` напрямую → видим какая подсистема `down`.
2. **`scheduler: stale`** → `docker compose ps scheduler` (возможно Exited), `docker compose logs --tail=100 scheduler`. Чаще всего — `docker compose restart scheduler` решает.
3. **`db: fail`** → `docker compose ps postgres`, `docker compose logs postgres`. Если контейнер не up — `restart postgres`. Если up но падает — посмотреть `df -h` (диск кончился?).
4. **`redis: fail`** → аналогично.
5. **Connection refused / timeout** → SSH на VPS, `df -h`, `free -m`, `docker ps`. Возможно VPS перезагружался — `make up` и проверить.

## Что НЕ настроено сейчас (TODO)

- Метрики (Prometheus/Grafana) — для одного сервера overkill.
- Алёрты на cost (если Anthropic API улетает в небеса) — отдельная история через биллинг-API.
- Логи в централизованный сервис (Loki/ELK) — пока хватает `docker logs`.
