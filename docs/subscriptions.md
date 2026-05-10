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
- `activatePro(user, periodStart, periodEnd, externalId, now)` — после успешного платежа.
- `cancel(sub, now)` — статус `Cancelled`, доступ до `currentPeriodEnd`.
- `expire(sub, now)` — статус `Expired`, доступ закрыт.
- `getCurrentPlan(user)` — `Pro` если активная подписка, иначе `Free`.
- `getActiveSubscription(user)` — usable-подписка (trialing/active/cancelled-но-не-истёкшая) или null.
- `isRefundEligible(sub, now)` — true если прошло меньше `REFUND_WINDOW_DAYS` с `currentPeriodStart`.

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
- Кнопка ведёт на callback `upgrade:info` → `UpgradeInfoCallbackHandler`. В S2 это заглушка-alert «Скоро появится /upgrade», в S3 будет полноценный экран.

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
- Чтобы потестировать триал/лимиты — сними `is_admin` (через БД) или используй другой Telegram-аккаунт. UI-команда `/admin grant_trial` появится в S3.

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

## Что НЕ сделано (этапы дальше)

| Этап | Что добавляется |
|---|---|
| S3 | UI в боте — `/upgrade`, `/subscription`, статус-блок в `/help`, `/admin grant_trial` |
| S4 | Telegram Payments — реальная оплата Pro |
| S5 | Auto-rebill — продление через `external_subscription_id` ЮKassa |
| S6 | Реальный refund через ЮKassa Refund API |
