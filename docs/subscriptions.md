# Подписки и биллинг

## Тарифы

| Тариф | Лимит действий | Цена | Период |
|-------|----------------|------|--------|
| Free  | 50/мес         | 0    | Скользящий 30 дней от первого действия |
| Pro   | 1500/мес       | ₽490 | Календарный месяц от оплаты            |

Триал: 7 дней Pro автоматически при первом `/start`. Один раз на пользователя — повторный триал не даётся (защита от абуза).

## Архитектура

```
User
 │
 ├─ Subscription (plan, status, period, trial_ends_at, external_id)
 │     plan: Free | Pro | Standard | Family (расширяемо)
 │     status: trialing | active | past_due | cancelled | expired
 │
 ├─ Payment (amount_minor, currency, status, external_id, provider_data)
 │     status: pending | succeeded | failed | refunded
 │
 └─ UsageCounter (free_count + free_period_start, pro_count + pro_period_start)
```

Все суммы — в копейках (`amountMinor: int`), стандарт для финансовых данных. Конвертация в «490 ₽» — только при отображении.

## Сервисы

### `App\Service\Subscription\SubscriptionService`
Главная точка для мутаций подписок:
- `startTrial(user, now)` — идемпотентен. null если у юзера была хоть какая-то подписка раньше.
- `activatePro(user, periodStart, periodEnd, externalId, now)` — после успешного платежа. Если был триал — фиксирует `convertedFromTrialAt` для аналитики.
- `forceStartTrial(user, now)` — админский force-trial: гасит существующую подписку в `expired` и создаёт новый триал. Использует только `/admin grant_trial`.
- `forceActivatePro(user, days, now)` — админский «подарить Pro на N дней» без оплаты. `externalSubscriptionId` остаётся null.
- `cancel(sub, now)` — статус `Cancelled`, доступ до `currentPeriodEnd`.
- `expire(sub, now)` — статус `Expired`, доступ закрыт.
- `getCurrentPlan(user)` — `Pro` если активная подписка, иначе `Free`.
- `getActiveSubscription(user)` — usable-подписка (trialing/active/cancelled-но-не-истёкшая) или null.
- `isRefundEligible(sub, now)` — true если прошло меньше `REFUND_WINDOW_DAYS` с `currentPeriodStart`.

### `App\Service\Subscription\SubscriptionStatsService`
Считает числа для `/admin stats`: пользователи (всего/allowed/admins), подписки по статусам, MRR (active × цена Pro), активность за 7 дней (новые регистрации, стартованные триалы, конверсии из триала в Pro, отмены). Без хранилища метрик — каждый счётчик ad-hoc COUNT-запросом, для S3 этого достаточно.

### `App\Service\Subscription\UsageTracker`
- `recordAction(user, now)` — +1 к счётчику текущего тарифа. Создаёт `UsageCounter` лениво. Сбрасывает counter если истёк период.
- `getRemainingActions(user, now)` — сколько осталось до лимита.
- `canPerformAction(user, now)` — true если ещё есть запас. Это будет основная функция для AssistantHandler в S2.
- `getNextResetAt(user, now)` — когда обновится квота. Для Free — `freePeriodStart + 30d`; для Pro — `currentPeriodEnd` подписки.

### `App\Service\PlanCatalog`
Типизированный доступ к limits/prices из `config/packages/subscription.yaml`. Все тарифы и параметры — в одном месте.

## Расширение

Чтобы добавить тариф Standard:
1. В `Plan` enum: `case Standard = 'standard';`
2. В `config/packages/subscription.yaml`: блок `standard: { action_limit: ..., price_rub_minor: ... }`
3. Все сервисы (`getCurrentPlan`, `recordAction`, `actionLimit`) автоматически подхватят. Никаких правок в core-логике.

## Что считается «действием»

Один цикл обращения к AI с генерацией ответа:

- **`AssistantHandler::__invoke`** — свободный текст в боте. Tool calling до 5 итераций — это всё равно одно действие, считается единожды по успешному завершению цикла.
- **`FreeHandler::__invoke`** — `/free <время>`. Один вызов TaskAdvisor → одно действие.

Платный путь — это `canPerformAction` → AI-вызов → `recordAction`. **Если AI-вызов упал** (timeout/rate-limit/5xx) — `recordAction` НЕ вызывается, юзер не теряет лимит из-за наших проблем.

Read-операции и детерминированные команды бесплатны:
- `/list`, `/done`, `/snooze`, `/block`, `/deps` (как командой, так и по inline-кнопке)
- Получение напоминаний от scheduler'а
- Reroll-варианты в `/free` (нажатие «🔄 Другие варианты» — это уже AI-вызов, но мы не считаем второе действие в S2; пересмотрим если станет узким местом)
- `/admin *` команды
- `/start`, `/help`, `/reset`

## UX триала и лимитов

**Первый `/start` нового пользователя:**
- Не админ → автоматически создаётся 7-дневный триал Pro (`SubscriptionService::startTrial`).
- Приветствие со строкой «🎁 У тебя 7 дней безлимитного Pro» (`WelcomeMessageBuilder::buildWithTrial`).

**Повторный `/start`:**
- Триал не даётся повторно (защита от абуза в `SubscriptionService::startTrial` через идемпотентность по `userId`).
- Стандартное приветствие без подписочной риторики.

**Когда лимит исчерпан** (на 51-м действии для Free, на 1501-м для Pro):
- AssistantHandler/FreeHandler возвращают soft block: текст «🔒 Ты использовал лимит действий тарифа …», кнопка «💎 Узнать про Pro» (`SoftBlockMessageBuilder`).
- AI-вызов не происходит, Anthropic не дёргается.
- Кнопка ведёт на callback `upgrade:info` → `UpgradeCallbackHandler` (S3) → открывает экран `/upgrade`.

**Триальные предупреждения** (cron каждые 5 минут, `TrialNotifier`):
- Через 3 дня до конца — «⏰ Через 3 дня закончится бесплатный Pro».
- Через 1 день — «⏰ Завтра закончится бесплатный Pro».
- В момент истечения — «🔚 Триал Pro закончился».
- Дедупликация через `subscriptions.notification_3d_sent_at` / `_1d_sent_at` / `_expired_sent_at`.
- Quiet hours соблюдаются для предупреждений 3д/1д. Для «закончился» — нет (свершившийся факт, нельзя отложить).

**Истечение** (cron каждые 5 минут, `SubscriptionExpirer`):
- Подписка с `currentPeriodEnd < now` и status в (`trialing`, `active`, `cancelled`) переводится в `expired`.
- После этого `getCurrentPlan(user)` возвращает `Free`, лимит сбрасывается на 50/мес.

## Админ

`User.isAdmin = true` (см. `AccessGate`):
- Безлимитен. `UsageTracker::canPerformAction` всегда `true`, `recordAction` — no-op (защита in depth).
- Подписка не создаётся даже на `/start`. `SubscriptionService::getActiveSubscription` для админа = `null`.
- Стандартное приветствие, без 🎁 и без упоминания планов.
- Чтобы потестировать триал/лимиты — используй другой Telegram-аккаунт и выдай ему тариф через `/admin grant_trial <tg_id>` или `/admin grant_pro <tg_id> <days>`.

## Возвраты

Полная сумма если прошло меньше `REFUND_WINDOW_DAYS` (по умолчанию 7) с начала оплаченного периода. Иначе отказ. В S6 — реальный возврат через ЮKassa. Сейчас — только метод `isRefundEligible()`.

## Env-переменные

| Переменная | Назначение | По умолчанию |
|---|---|---|
| `PRICE_PRO_MONTHLY_RUB_MINOR` | Цена Pro в копейках | 49000 (490 ₽) |
| `LIMIT_FREE_ACTIONS` | Лимит действий Free | 50 |
| `LIMIT_PRO_ACTIONS` | Лимит действий Pro | 1500 |
| `TRIAL_DAYS` | Длина триала | 7 |
| `REFUND_WINDOW_DAYS` | Окно возврата денег | 7 |

## Что сделано в S2

- Backfill-миграция `Version20260510000000` — для существующих `is_allowed=true` не-админов выдаётся 7-дневный триал, чтобы они не «потеряли» доступ при выкатке. Идемпотентна.
- 3 столбца дедупликации уведомлений: `subscriptions.notification_3d_sent_at`, `_1d_sent_at`, `_expired_sent_at`.
- `StartHandler` — авто-старт триала на первом `/start`, разные приветствия.
- `WelcomeMessageBuilder` — изоляция текстов приветствия (admin / триал / стандарт).
- `SoftBlockMessageBuilder` + `UpgradeInfoCallbackHandler` — soft block при превышении лимита и заглушка `/upgrade`.
- `UsageTracker` — фастпасы для админа (`canPerformAction=true`, `recordAction=no-op`).
- `AssistantHandler` / `FreeHandler` — лимит-чек до AI-вызова, `recordAction` после успеха.
- `TrialNotifier` — cron-уведомления 3д/1д/expired. Dispatched через `ReminderSchedule` каждые 5 минут (`NotifyTrialEndingMessage`).
- `SubscriptionExpirer` — cron перевода истёкших подписок в `expired`. Dispatched каждые 5 минут (`ExpireSubscriptionsMessage`).
- 12 smoke-сценариев `s2-*`.

## Команды пользователя: `/upgrade`, `/subscription`

### `/upgrade`
Экран оформления Pro. Текст и клавиатура зависят от статуса (`UpgradeMessageBuilder`):

| Статус | Что показывается | Кнопки |
|---|---|---|
| admin | «Ты админ — безлимитный доступ». Подсказка про `/admin grant_*` | — |
| active Pro | «У тебя уже активна Pro», дата `currentPeriodEnd`, ссылка на `/subscription` | — |
| trialing | «🎁 Идёт триал», дата конца, цена и буллеты Pro | 💳 Оплатить ₽490 / ❌ Не сейчас |
| free / expired / cancelled | Полный pitch: 50 → 1500 действий + интеграции | 💳 Оплатить ₽490 / ❌ Не сейчас |

Callback'и (`UpgradeCallbackHandler`):
- `upgrade:info` — soft-block кнопка ведёт сюда → открывает экран `/upgrade`.
- `upgrade:pay` — отправляет реальный ЮKassa-инвойс через Telegram Payments (S4). Для админа — админский текст; для уже active Pro — alert «уже Pro»; если YooKassa не сконфигурирована — fallback-stub. Подробно — `docs/payments.md`.
- `upgrade:later` — редактирует сообщение в «ОК, когда захочешь — /upgrade», убирает клавиатуру.

### `/subscription`
Статус текущей подписки (`SubscriptionMessageBuilder`):

| Статус | Текст | Кнопки |
|---|---|---|
| admin | «Безлимитный доступ, тарифов нет» | — |
| active Pro | «💎 Pro, статус активна, следующее списание ДД.ММ, использовано N/1500» | ❌ Отменить подписку |
| trialing | «🎁 Триал Pro, заканчивается через N дней, использовано N/1500» | 💎 Перейти на Pro |
| cancelled | «💎 Pro (отменена). Действует до ДД.ММ, дальше Free» | 💎 Возобновить подписку |
| free / expired | «🆓 Free, использовано N/50 в скользящий месяц, лимит обновится ДД.ММ» | 💎 Узнать про Pro |

Callback `subscription:cancel` — двухшаговый flow:
1. `subscription:cancel` — confirm-экран «⚠️ Точно отменить? Доступ до ДД.ММ» + кнопки Да/Нет.
2. `subscription:cancel:confirm` — вызывает `SubscriptionService::cancel`, status → `cancelled`. Доступ Pro продолжает работать до `currentPeriodEnd`.
3. `subscription:cancel:abort` — возвращаемся к экрану `/subscription`.

## Админские команды для подписок

`/admin grant_trial <telegram_id>` — выдаёт 7-дневный триал указанному пользователю. Если у пользователя уже была подписка (любого статуса) — показывает confirm («это обнулит историю»). Реализация — `SubscriptionService::forceStartTrial` (не идемпотентно, в отличие от обычного `startTrial`).

`/admin grant_pro <telegram_id> <days>` — выдать Pro на N дней без оплаты. Confirm если была подписка. Реализация — `SubscriptionService::forceActivatePro`, `externalSubscriptionId=null`.

`/admin revoke_subscription <telegram_id>` — мгновенно перевести активную подписку в `expired`, без grace period. Confirm обязательный.

`/admin stats` — текст со статистикой (`SubscriptionStatsService` + `AdminStatsMessageBuilder`): пользователи (total/allowed/admins), подписки (trial/active/cancelled-но-активны/expired), MRR (active Pro × цена тарифа), активность за 7 дней (регистрации/триалы/конверсии/отмены).

Все confirm-flow используют callback'и `admin:grant_trial:<tg_id>:confirm|abort`, `admin:grant_pro:<tg_id>:<days>:confirm|abort`, `admin:revoke_subscription:<tg_id>:confirm|abort`. Доступ — только для пользователя с `is_admin=true`.

## Что сделано в S3

- Поле `subscriptions.converted_from_trial_at` (миграция `Version20260514000000`) — заполняется при `activatePro` если предыдущий статус был `trialing`. Источник для конверсионной метрики в `/admin stats`.
- `SubscriptionService::forceStartTrial` / `forceActivatePro` — админские force-методы без идемпотентности (старая подписка гасится в `expired`).
- `UpgradeHandler` / `UpgradeCallbackHandler` / `UpgradeMessageBuilder` — экран `/upgrade` для четырёх состояний (admin/active/trial/free) + callback'и `info/pay/later`. Удалён `UpgradeInfoCallbackHandler` (S2-заглушка).
- `SubscriptionHandler` / `SubscriptionCallbackHandler` / `SubscriptionMessageBuilder` — экран `/subscription` + двухшаговая отмена.
- `AdminHandler` расширен подкомандами `grant_trial`, `grant_pro`, `revoke_subscription`, `stats`. Новый `AdminCallbackHandler` для подтверждений.
- `SubscriptionStatsService` + `AdminStatsMessageBuilder` — простая аналитика для `/admin stats` без хранилища метрик.
- Раздел «💳 Подписка» в `/help`.
- 8 smoke-сценариев `s3-*`.

## Что сделано в S4

Реальные платежи через Telegram Payments + ЮKassa. Полный flow и архитектура — в `docs/payments.md`. Кратко:

- `App\Service\Subscription\Provider\YooKassa\YooKassaConfig` — credentials под test/live, fail-fast в конструкторе.
- `InvoicePayloadBuilder` — payload + provider_data (54-ФЗ) для `sendInvoice`.
- `PaymentValidator` — валидация `pre_checkout_query` (user, amount, currency, уже-Pro).
- `PaymentProcessor` — идемпотентная обработка `successful_payment`: одна запись `Payment` + активация Pro через `activatePro`.
- `PreCheckoutQueryHandler` / `SuccessfulPaymentHandler` — Nutgram-обёртки.
- `AdminPaymentNotifier` — пинг админу о новом платеже (no-op в test).
- В test-режиме `UpgradeMessageBuilder` дописывает строку про тестовую карту в pitch.
- Миграция `Version20260516000000` — partial UNIQUE по `payments.external_payment_id` (идемпотентность на уровне БД).
- 8 smoke-сценариев `s4-*`.

## Что НЕ сделано (этапы дальше)

| Этап | Что добавляется |
|---|---|
| S5 | Auto-rebill — продление через `external_subscription_id` ЮKassa, webhook от ЮKassa, email-уведомления |
| S6 | Реальный refund через ЮKassa Refund API + admin stats по refund'ам |
