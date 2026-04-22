DC = docker compose

.PHONY: up down restart build bash logs ps clean bot-logs bot-restart scheduler-logs scheduler-restart smoke-all smoke-reset smoke-assistant smoke-parser smoke-scenario smoke-tick cache-clear

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

# Очистить prod-кэш во всех контейнерах. С 2026-04-22 scheduler/bot
# работают в APP_ENV=dev и кэш не надо чистить вручную, но если на проде
# будет prod-режим — команда пригодится.
cache-clear:
	$(DC) run --rm --user root --entrypoint sh php -c 'rm -rf /var/www/app/var/cache/prod'
