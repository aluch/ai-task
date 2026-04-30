# CI/CD

Автоматический деплой на VPS через GitHub Actions. Push в `main` → SSH-сессия на сервер → `bin/deploy.sh` → уведомление в Telegram.

## Архитектура

```
git push main
     │
     ▼
GitHub Actions (.github/workflows/deploy.yml)
     │
     │ ssh-agent (DEPLOY_SSH_KEY)
     ▼
VPS: ssh ${VPS_USER}@${VPS_HOST}
     │
     │ bash -lc "cd ~/ai-task && bash bin/deploy.sh"
     ▼
deploy.sh:
  git pull → build → migrate → seed → up -d → set-webhook → healthcheck
     │
     ▼
curl Telegram Bot API → notify chat
```

`concurrency: deploy-prod` + `cancel-in-progress: true` гарантируют что одновременно идёт **только один** деплой. Если пушнули второй коммит, пока первый ещё не закончил — старый прогон отменяется. Без этого был бы race на сервере (два `git pull` параллельно).

## Подготовка VPS

### 1. Создать deploy-пользователя

```bash
# на VPS под root
adduser --disabled-password --gecos "" deploy
usermod -aG docker deploy            # доступ к docker-сокету
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
touch /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
```

### 2. Склонировать репозиторий в `~deploy/ai-task`

```bash
sudo -u deploy -H bash -c '
  cd ~ &&
  git clone git@github.com:<owner>/ai-task.git &&
  cd ai-task &&
  cp .env.example .env
'
# заполнить .env (см. docs/deployment.md), особенно: DOMAIN,
# TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET, ANTHROPIC_API_KEY,
# POSTGRES_PASSWORD, APP_SECRET.
```

Первый раз деплой запустить вручную (`sudo -u deploy bash bin/deploy.sh`) — последующие пойдут автоматически из CI.

## GitHub Secrets

Settings → Secrets and variables → Actions → New repository secret. Нужны 5 секретов:

### `DEPLOY_SSH_KEY` — приватный SSH-ключ для CI

**Генерируем отдельный ключ для CI** (не используем личный):

```bash
ssh-keygen -t ed25519 -f /tmp/github_deploy -N "" -C "github-actions@ai-task"
```

- Содержимое `/tmp/github_deploy.pub` → добавить в `/home/deploy/.ssh/authorized_keys` на VPS.
- Содержимое `/tmp/github_deploy` (приватная часть, **с** `-----BEGIN OPENSSH PRIVATE KEY-----` и финальной newline) → в этот секрет.
- Удалить локальные копии: `shred -u /tmp/github_deploy /tmp/github_deploy.pub`.

> Ограничь ключ: в `authorized_keys` перед ключом можно дописать `command="cd ~/ai-task && bash bin/deploy.sh",no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 AAAA...` — тогда даже при компрометации ключа атакующий сможет запустить только `deploy.sh`. Опционально, но рекомендую после первой удачной поставки.

### `VPS_HOST` — IP или DNS-имя сервера

Например `79.137.199.38` или `vps.example.com`. Без `https://`, без порта.

### `VPS_USER` — `deploy`

(или то имя что создал в шаге 1).

### `TG_NOTIFY_BOT_TOKEN` — токен бота для уведомлений CI

Лучше создать **отдельного** бота через @BotFather (имя типа `@pomni_deploy_bot`) — иначе логи деплоя будут приходить в продовый `@pomniapp_bot` и засорять историю пользовательских разговоров.

```
/newbot → название → @pomni_deploy_bot → токен в этот секрет
```

### `TG_NOTIFY_CHAT_ID` — куда слать уведомления

Твой Telegram user ID (узнать через @userinfobot). Бот должен сначала получить от тебя `/start`, иначе он не сможет писать первый.

## Workflow в действии

После добавления всех секретов — push любой мелкий коммит в main. Идём в **Actions** tab репозитория, видим прогон `Deploy to Production`. Через 1-2 минуты — уведомление в Telegram:

```
✅ Deploy успешен

коммит: a3f8b2e1
автор: <username>
лог: https://github.com/<owner>/ai-task/actions/runs/<run_id>
```

Если в логах ошибка — придёт `❌ Deploy упал` со ссылкой. Открываешь, смотришь в каком шаге упало, фиксишь, пушишь снова.

## Healthcheck-gate

Последний шаг `deploy.sh` — 60-секундный poll на `https://${DOMAIN}/health`. Опрашивается каждые 5 секунд (12 попыток). Если за минуту не получили 200 — `exit 1`, CI помечает как failure, идёт уведомление об ошибке.

Это ловит:
- Неуспешный старт php-fpm (битый opcache, ошибка в DI)
- Caddy не получил TLS-сертификат от Let's Encrypt (DNS не настроен, rate-limit от LE)
- БД упала после миграций
- Redis недоступен

## Ручной запуск

Иногда нужно перевыкатить без коммита (например, поменял `.env` на сервере). В Actions UI: `Deploy to Production` → `Run workflow` → `main` → запустится тот же flow.

## Откат

GitHub Actions сам по себе откатов не делает. Для отката:

1. Локально: `git revert <bad-sha> && git push` — Actions выкатит revert-коммит как обычно.
2. Срочно (например база сломана): SSH на VPS под `deploy`, `cd ~/ai-task && git reset --hard <good-sha> && bash bin/deploy.sh`.

Миграции при откате могут потребовать `doctrine:migrations:migrate prev` — `deploy.sh` это не покрывает, делать руками.

## Что НЕ настроено сейчас (TODO)

- **smoke-тесты на CI до деплоя**: `make smoke-all` использует Anthropic API, потребовал бы тестовый ключ + 2-3 минуты прогона. Когда будет полезно — добавить отдельный job `test`, который должен пройти до `deploy`.
- **Стейджинг-окружение**: сейчас main → сразу прод. Можно добавить `staging`-ветку с отдельным workflow + поддоменом.
- **Автоматический откат при failed healthcheck**: сейчас CI помечает как failure, но прод остаётся в полуфабрикатном состоянии (новый код запущен). Можно добавить `rollback`-step который делает `git reset --hard HEAD~1 && bin/deploy.sh`.

## Troubleshooting

**`ssh: connect to host ... port 22: Connection refused`** — `VPS_HOST` неверный или firewall на VPS блокирует Actions IP. Проверить `ufw status`, открыть 22 на all (или whitelistить GH IP-диапазон, но он [широкий](https://api.github.com/meta)).

**`Permission denied (publickey)`** — публичный ключ из `DEPLOY_SSH_KEY.pub` не в `authorized_keys` на VPS, или права на `~/.ssh` неверные. Проверить: `chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys` под deploy-пользователем.

**`docker: command not found`** на CI — `bash -lc` не подтянул PATH. Убедись что `deploy` пользователь в группе `docker`: `groups deploy` → должно быть `docker` в списке. Если нет — `usermod -aG docker deploy`, потом logout/login.

**`the input device is not a TTY`** в одной из docker-команд — добавить `-T` к `docker compose run`/`exec`. В `deploy.sh` уже сделано, но если добавляешь новые команды — не забывай.

**Healthcheck failed after 60s** — `docker compose -f docker-compose.prod.yml logs caddy php` на VPS. Чаще всего: Caddy не получил сертификат (DNS не настроен или Let's Encrypt rate-limit), или php-fpm падает на старте (опечатка в `.env`, миграции не прошли).

**Уведомление не пришло, но workflow зелёный** — `TG_NOTIFY_BOT_TOKEN` или `TG_NOTIFY_CHAT_ID` неверный. В Actions логе шаг «Notify Telegram on success» покажет ответ от Telegram API. Самая частая причина — бот ни разу не получал `/start` от чата, поэтому не может писать первым. Открой бота в Telegram, нажми `/start`.
