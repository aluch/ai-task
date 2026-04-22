DC = docker compose

.PHONY: up down restart build bash logs ps clean bot-logs bot-restart scheduler-logs scheduler-restart smoke-all smoke-reset smoke-assistant smoke-parser smoke-scenario smoke-tick cache-clear cache-rebuild

up:
	$(DC) up -d

down:
	$(DC) down

restart:
	$(DC) restart

build:
	$(DC) build

bash:
	$(DC) exec php sh

logs:
	$(DC) logs -f --tail=200

ps:
	$(DC) ps

clean:
	$(DC) down -v --remove-orphans

bot-logs:
	$(DC) logs -f bot

bot-restart:
	$(DC) restart bot

scheduler-logs:
	$(DC) logs -f scheduler

scheduler-restart:
	$(DC) restart scheduler

# Smoke-команды — быстрая самопроверка reminder-пайплайна + AI-промптов.
# Подробности: docs/testing/smoke.md
smoke-all:
	$(DC) exec --user 1000:1000 php php bin/console app:smoke:all

smoke-reset:
	$(DC) exec --user 1000:1000 php php bin/console app:smoke:reset

smoke-assistant:
	$(DC) exec --user 1000:1000 php php bin/console app:smoke:assistant "$(msg)"

smoke-parser:
	$(DC) exec --user 1000:1000 php php bin/console app:smoke:parser "$(msg)"

smoke-scenario:
	$(DC) exec --user 1000:1000 php php bin/console app:smoke:reminder-scenario $(name)

smoke-tick:
	$(DC) exec --user 1000:1000 php php bin/console app:smoke:reminder-tick

# Аварийная очистка Symfony-кэша во всех трёх контейнерах (bot,
# scheduler, php) + рестарт. Кэши изолированы по named volumes
# (bot_cache/scheduler_cache/php_cache) — очистка через exec, rm -rf
# внутри контейнера, чтобы не трогать хостовый bind mount.
# Используй когда бот падает с «Failed opening required
# .../ContainerXXX/...Service.php» или со stale-DI после рефакторинга.
cache-clear:
	$(DC) exec --user root bot sh -c 'rm -rf /var/www/app/var/cache/*' || true
	$(DC) exec --user root scheduler sh -c 'rm -rf /var/www/app/var/cache/*' || true
	$(DC) exec --user root php sh -c 'rm -rf /var/www/app/var/cache/*' || true
	$(DC) restart bot scheduler php

# После cache-clear — прогреть dev-кэш в php-контейнере (быстрее чем
# ждать первого запроса).
cache-rebuild: cache-clear
	$(DC) exec --user 1000:1000 php php bin/console cache:warmup --env=dev
