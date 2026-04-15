DC = docker compose

.PHONY: up down restart build bash logs ps clean

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
