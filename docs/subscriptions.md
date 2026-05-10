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

Один цикл обращения к Ассистенту: пользователь написал → AI обработал → ответ. В одном цикле может быть несколько tool calls — это всё равно одно действие.

Read-операции бесплатны:
- `/list`, `/done` через кнопку, `/snooze` через кнопку
- Получение напоминаний от scheduler'а
- `/admin *` команды (admin-only, не учитывается)

Конкретное место подсчёта (вызов `UsageTracker::recordAction`) появится в S2 в `AssistantHandler`.

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

## Что НЕ сделано в S1 (этапы дальше)

| Этап | Что добавляется |
|---|---|
| S2 | Лимит-чек в `AssistantHandler` — отказ при `canPerformAction = false` |
| S3 | UI в боте — `/upgrade`, `/subscription`, статус-блок в `/help` |
| S4 | Telegram Payments — реальная оплата Pro |
| S5 | Auto-rebill — продление через `external_subscription_id` ЮKassa |
| S6 | Реальный refund через ЮKassa Refund API |
