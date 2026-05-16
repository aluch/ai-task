# Платежи через Telegram Payments + ЮKassa (S4)

## Архитектура

```
User → Bot (sendInvoice)         → Telegram (UI)      → ЮKassa (списание)
                                                         │
User ← Bot (activatePro)         ← Telegram (successful_payment callback)
```

Промежуточный шаг — `pre_checkout_query`: Telegram спрашивает у бота «можно ли проводить платёж?». Это последний шанс отказаться (если, например, сумма в payload не сходится с тем, что Telegram собирается списать).

## Поток

1. Пользователь в `/upgrade` жмёт «💳 Оплатить ₽490» → callback `upgrade:pay`.
2. `UpgradeCallbackHandler::handlePay`:
   - проверяет, что юзер не админ и не active Pro;
   - проверяет `YooKassaConfig::isConfigured()` (provider_token непустой);
   - вызывает `$bot->sendInvoice(...)` с payload + provider_data.
3. Telegram открывает встроенный платёжный экран. Пользователь оплачивает.
4. Перед списанием Telegram присылает `pre_checkout_query` → `PreCheckoutQueryHandler` → `PaymentValidator` валидирует и отвечает `answerPreCheckoutQuery(ok: true|false)`.
5. После успешного списания Telegram присылает `successful_payment` в чат → `SuccessfulPaymentHandler` → `PaymentProcessor`:
   - проверка идемпотентности по `external_payment_id`;
   - запись `Payment` в БД;
   - вызов `SubscriptionService::activatePro` (триал/cancelled заменяются на active);
   - ответ юзеру «🎉 Подписка активирована до ДД.ММ»;
   - в live-режиме — нотификация админа через `AdminPaymentNotifier`.

## Test / Live режимы

Переключается через `YOOKASSA_MODE` в `.env`:

| Значение | Источник credentials | Реальные деньги |
|---|---|---|
| `test` | `YOOKASSA_TEST_*` | Нет, sandbox |
| `live` | `YOOKASSA_LIVE_*` | Да, production |

Тестовая карта (sandbox ЮKassa): `5555 5555 5555 4444`, любые CVV и срок, 3DS пароль — любой текст.

В test-режиме `UpgradeMessageBuilder` дописывает в конец `/upgrade`-pitch'а строку «Test mode — реальная сумма не спишется. Используй тестовую карту 5555 5555 5555 4444.» — защита от того, что мы сами забудем что стенд не live.

`AdminPaymentNotifier::notifyPaid` в test-режиме — no-op (иначе админу прилетал бы спам при каждом тестировании).

## Payload и его лимит

Telegram ограничивает `invoice_payload` 128 байтами. Поэтому в `InvoicePayloadBuilder::buildPayload` храним только critical:

```json
{"user_id":"<uuid>","plan":"pro","period_days":30,"amount_minor":49000}
```

Около 100 байт (UUID занимает 36). `created_at` опущен — UUID v7 уже несёт момент создания, и не хочется упираться в лимит из-за nice-to-have. Если payload разрастётся и превысит 128 байт — `LogicException` сработает до сабмита в Telegram.

`PaymentValidator::validatePreCheckout` проверяет:
- `user_id` в payload == UUID DB-юзера, найденного по `from.id`;
- `amount_minor` в payload == `total_amount` от Telegram;
- `currency` == `RUB`;
- у юзера нет active Pro (active важен — на trial/cancelled покупка допустима).

При rejection пользователь видит в Telegram-UI human-readable причину через `answerPreCheckoutQuery(ok: false, error_message: ...)`.

## Идемпотентность

Защита от дубликатов:

1. **Partial UNIQUE-индекс** `uniq_payments_external_payment_id ON payments (external_payment_id) WHERE external_payment_id IS NOT NULL` (миграция `Version20260516000000`). Партиальность — потому что для админских grant'ов `external_payment_id` остаётся NULL.
2. **`PaymentProcessor::process`** перед INSERT'ом делает `findOneBy(['externalPaymentId' => $id])` и возвращает существующий результат с `idempotentSkip=true`, если запись уже есть.

В обоих местах источник истины — `provider_payment_charge_id` (идентификатор от ЮKassa, прокинутый Telegram'ом).

`SuccessfulPaymentHandler` при `idempotentSkip=true` шлёт пользователю «✅ Платёж уже обработан, твоя подписка активна» — без второго «🎉».

## Фискализация и 54-ФЗ

Текущая модель — **самозанятый**. ЮKassa в этом потоке **не** фискальный агент: формирование чека 54-ФЗ — обязанность владельца кабинета через приложение «Мой налог» по факту поступления денег. Это операционный шаг вне нашего кода.

В коде это означает: `provider_data` в `$bot->sendInvoice(...)` **не передаётся**. Если передать `receipt` без `customer.email`/`customer.phone`, ЮKassa молча отклоняет invoice ещё до этапа списания (Telegram показывает «Заплатить не получилось», в логе операций ЮKassa никакого следа). Так и был баг: `InvoicePayloadBuilder::buildProviderData()` строил receipt без customer, и пользователи упирались в фиктивное «Заплатить не получилось».

`buildProviderData()` оставлен в коде как dead code с пометкой — пригодится, если в будущем перейдём на ИП/ООО:

1. Включить «Фискализацию» в личном кабинете ЮKassa.
2. Включить `customer.email` или `customer.phone` в receipt — без этого ЮKassa отклонит invoice.
3. Получать email/phone у пользователя через invoice options Telegram (`need_email=true` / `need_phone_number=true`) или из профиля.

До тех пор `provider_data` не передаём.

## Edge cases

- **Активный Pro** жмёт «Оплатить» → `UpgradeCallbackHandler` отвечает alert'ом «У тебя уже Pro» без отправки invoice.
- **Админ** жмёт «Оплатить» → админский текст («Используй /admin grant_*»).
- **Не сконфигурирован** (provider_token пуст) → `UpgradePayCallbackHandler` шлёт fallback-stub. Логируется warning.
- **Дубль callback'а** (Telegram retry) → второй `successful_payment` с тем же `provider_payment_charge_id` фиксируется как `idempotentSkip`, юзеру отвечаем «уже обработано».
- **Триал → платёж** → `activatePro` фиксирует `convertedFromTrialAt` (метрика конверсии для `/admin stats`).

## Гарантия что Telegram присылает pre_checkout_query

По умолчанию Telegram **не шлёт** `pre_checkout_query` и `successful_payment` в webhook — даже если у бота зарегистрированы соответствующие handler'ы. Без явного списка `allowed_updates` Telegram присылает только `message` и `callback_query`. Если `pre_checkout_query` не дойдёт до нас за 10 секунд, Telegram молча таймаутит платёж с `BOT_PRECHECKOUT_TIMEOUT` — у пользователя списания не происходит, у нас в логах ничего.

Источник истины — `bin/set-webhook.sh`, там в `setWebhook`-запросе зашит минимальный набор:

```
allowed_updates: ["message", "edited_message", "callback_query", "pre_checkout_query", "shipping_query"]
```

`bin/deploy.sh` дёргает `bin/set-webhook.sh` при каждом деплое, поэтому любой ручной фикс через Telegram API будет перезаписан на следующем rollout'е. Если меняешь webhook URL или добавляешь новый тип update'а — правь именно этот скрипт, не через API.

`shipping_query` оставлен «на всякий случай» — мы пока не запрашиваем доставку (`is_flexible=false` по умолчанию), но если в будущем добавим need_shipping_address, тип уже будет в списке.

## Безопасность

- `provider_token` / `secret_key` — никогда в логи. Логируется только `payment_id`, `external_id`, `amount_minor`, `user_id` (UUID).
- payload подписан намерением (`user_id`/`amount_minor`), проверяется в pre_checkout. Подмена суммы на стороне клиента отбивается.
- `provider_token` хранится в env-переменной, путь от env до сервиса — через `YooKassaConfig`, без хардкодов.

## Recurring billing (S5)

После первого Telegram-платежа подписка может автоматически продлеваться через сохранённый токен карты. Подробное руководство по UX-стейтам и сценариям ошибок — `docs/subscriptions-recurring.md`.

### Архитектура

```
First payment (S4):
    User → /upgrade → sendInvoice(provider_data.save_payment_method=true)
        → Telegram → ЮKassa (списание + сохранение карты)
        ← successful_payment
    PaymentProcessor → GET /payments/{id} via YooKassaApiClient
        → payment_method.id записываем в Subscription.savedPaymentMethodId

Auto-rebill (S5, каждые 15 минут):
    RebillScheduler.run(now)
      ├─ notifyUpcomingCharges       за 23-25 часов до периода — Telegram-уведомление
      ├─ initiateCharges             за час до периода — RecurringAttempt + POST /payments
      ├─ retryFailedAttempts         failed > 24h, attempt < 3 — следующий attempt
      └─ expirePastDueSubscriptions  3 failed + последняя > 24h — status=expired

YooKassa async result:
    ЮKassa → POST /api/yookassa/webhook (с payment.succeeded / payment.canceled)
        → YooKassaWebhookController → RebillWebhookProcessor
        → RecurringAttempt.markSucceeded/Failed
        → Payment + activatePro (на 30 дней дальше) при успехе
```

### Ключевые объекты

| Класс | Назначение |
|---|---|
| `Subscription.savedPaymentMethodId` | Токен карты от ЮKassa (uuid). NULL — recurring невозможен. |
| `Subscription.autoRebillEnabled` | Пользовательский флаг включения автопродления. Default true. |
| `Subscription.notification24hBeforeRebillSentAt` | Дедуп уведомления «завтра спишется». |
| `Subscription.rebillFailedAttempts` | Сколько неудач подряд после последнего успеха. >= 3 — на expire. |
| `RecurringAttempt` | Журнал одной попытки списания (UUID, idempotenceKey, status, error_*). |
| `RebillScheduler` | 4 фазы: notify-24h / initiate / retry / expire. |
| `RebillWebhookProcessor` | Идемпотентная обработка webhook'а, продление подписки или фикс failure. |
| `YooKassaApiClient` | REST-клиент (Basic-auth shopId/secretKey): getPayment, createRecurringPayment, removePaymentMethod. |
| `YooKassaIpAllowlist` | Список IP-блоков ЮKassa, единственная защита webhook'а (подписей нет). |

### Идемпотентность

Тройной слой защиты от двойных списаний / двойных продлений:

1. `Idempotence-Key: <uuid>` в POST /payments — на стороне ЮKassa. UUID v4 на каждую попытку, хранится в `recurring_attempts.idempotence_key`. Сеть упала после нашего отправления → retry с тем же ключом не создаст второй платёж.
2. `recurring_attempts.external_payment_id` UNIQUE (partial индекс) — на стороне нашей БД. Защита от гонки одновременных webhook'ов.
3. `RebillWebhookProcessor` проверяет `attempt.status !== Pending` — повторный webhook → no-op.

### Webhook endpoint

`POST /api/yookassa/webhook` (см. `YooKassaWebhookController`). Защита — только IP allowlist (ЮKassa не подписывает webhook'и, факт зафиксирован в их документации).

Endpoint всегда возвращает `200 OK`, даже на внутренние ошибки — иначе ЮKassa спамит retry'ями по экспоненте, забивая лог и БД. Реальные ошибки видим в логах через `YooKassa webhook processing failed`.

### Что зарегистрировать в ЛК ЮKassa

После деплоя — **дважды** (для test- и live-магазинов):

1. Личный кабинет ЮKassa → Интеграция → HTTP-уведомления.
2. URL: `https://${DOMAIN}/api/yookassa/webhook`.
3. События: `payment.succeeded`, `payment.canceled`. (`payment.waiting_for_capture` опционально — мы не двухстадийные.)
4. Сохранить.

Без этого webhook'и не будут приходить — recurring'и зависнут в `pending` навсегда (и через 24 часа сюда не успеет придти retry).

### Логирование

С recurring деньги ходят без участия юзера — обязательно полная аудит-трасса. Логируем:

- `RebillScheduler tick` — каждый запуск с now.
- `Recurring attempt created` / `dispatched to YooKassa` / `failed at API call`.
- `YooKassa webhook processed` с result-кодом.
- `Recurring payment succeeded` / `failed` с attempt_id и причиной.

Никогда не логируем `provider_token`, `secret_key`, тело webhook'а целиком (там могут быть metadata пользователя).

## Безопасность

- `provider_token` / `secret_key` — никогда в логи. Логируется только `payment_id`, `external_id`, `amount_minor`, `user_id` (UUID).
- payload подписан намерением (`user_id`/`amount_minor`), проверяется в pre_checkout. Подмена суммы на стороне клиента отбивается.
- `provider_token` хранится в env-переменной, путь от env до сервиса — через `YooKassaConfig`, без хардкодов.
- Webhook endpoint защищён IP allowlist'ом (см. `YooKassaIpAllowlist`). ЮKassa подписей не присылает, IP-фильтр зафиксирован их документацией.

## Roadmap

- S4 — однократные платежи через Telegram Payments ✅
- S5 — auto-rebill через сохранённый токен + webhook ✅
- S6 — refund через ЮKassa Refund API + admin stats по refund'ам
