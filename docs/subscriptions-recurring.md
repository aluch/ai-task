# Auto-rebill: UX-стейты и сценарии (S5)

Подробное руководство по `auto_rebill_enabled` flow. Низкоуровневую архитектуру (классы, идемпотентность, IP allowlist) смотри в `docs/payments.md` § Recurring billing.

## Состояния подписки

Видно пользователю в `/subscription`. Каждое состояние — отдельный билдер в `SubscriptionMessageBuilder`.

### 1. Active + auto_rebill_enabled=true

«Я плачу за Pro и хочу продолжать автоматически». Это умолчательное состояние сразу после оплаты.

```
💎 Pro

Статус: активна
Следующее списание: 11.06.2026 — 490 ₽
Использовано в этом месяце: 47 / 1500

[❌ Отключить автопродление]
```

Сценарии:

- За **24 часа до** `current_period_end` — приходит уведомление «⏰ Завтра спишется 490 ₽» с кнопкой «Отключить автопродление» (= `subscription:disable_rebill`). Дедуп через `notification_24h_before_rebill_sent_at`.
- За **час до** `current_period_end` — `RebillScheduler.initiateCharges` создаёт `RecurringAttempt` и шлёт POST в ЮKassa.
- При успехе (webhook `payment.succeeded`) — `current_period_end += 30 дней`, новый цикл, флаг уведомления сбрасывается.

### 2. Active + auto_rebill_enabled=false

«Я хочу доступ до конца оплаченного периода, дальше — Free». Получается после нажатия «Отключить автопродление».

```
💎 Pro (автопродление отключено)

Действует до: 11.06.2026
Дальше — Free, если не возобновишь.

Использовано: 47 / 1500

[✅ Возобновить автопродление]
[💎 Оплатить ещё месяц]
```

Сценарии:

- `RebillScheduler` **не** трогает эту подписку — ни уведомлением, ни списанием.
- В момент `current_period_end` существующий `SubscriptionExpirer::tick` переведёт в `expired`.
- Кнопка «Возобновить» → `subscription:enable_rebill` → `auto_rebill_enabled=true`. Без confirm — действие безвредное.
- Кнопка «Оплатить ещё месяц» → переход на `/upgrade`, делает новый платёж со стандартным flow.

### 3. Active с rebill_failed_attempts > 0 (past_due-state)

Реальный статус всё ещё `Active`, но scheduler уже зафиксировал одну или две неудачные попытки. Пользователю в UI это видно только если он сам открыл `/subscription` — мы не делаем отдельный экран, но пишем личным сообщением:

```
⚠️ Не удалось списать 490 ₽ за продление Pro.
Попробуем ещё раз через 24 часа. Проверь карту: возможно недостаточно средств или истёк срок.
```

При 3-й неудаче:

```
❌ Не смогли списать 490 ₽ — три попытки подряд.
Подписка закроется через 24 часа. /upgrade чтобы сменить карту.
```

Через 24 часа после 3-й неудачи `expirePastDueSubscriptions` переводит в `Expired` и шлёт «🔚 Подписка Pro закрыта».

### 4. Trial / Free / Cancelled

Без изменений из S3. У триала `auto_rebill_enabled` хранится, но значения не имеет — recurring запускается только в `Active`.

## Callback-карта

| callback_data | действие |
|---|---|
| `subscription:disable_rebill` | Показать confirm-экран |
| `subscription:disable_rebill:confirm` | `auto_rebill_enabled=false`, остаётся active до конца периода |
| `subscription:disable_rebill:abort` | Возврат на /subscription без изменений |
| `subscription:enable_rebill` | `auto_rebill_enabled=true` (без confirm — безвредное) |

Старые `subscription:cancel:*` из S3 (hard-cancel в `Cancelled`) удалены — disable_rebill даёт ту же семантику пользователю «доступ до конца, дальше Free» более очевидно.

## Failed payments flow

1. **Попытка 1** (за час до period_end): `RecurringAttempt(attempt=1, status=pending)` → POST в ЮKassa.
   - Webhook `payment.succeeded` → продление + сброс `rebillFailedAttempts=0`.
   - Webhook `payment.canceled` → `markFailed`, `rebillFailedAttempts=1`. Юзеру: «попробуем завтра».
2. **Попытка 2** (через 24 часа от попытки 1, если failed): аналогично. `rebillFailedAttempts=2`.
3. **Попытка 3** (через 24 часа от 2): аналогично. `rebillFailedAttempts=3`. Юзеру: «закроется через 24 часа».
4. **Expire** (через 24 часа от 3-й failed): `SubscriptionStatus::Expired`, юзеру «подписка закрыта».

В коде: `RebillScheduler::initiateCharges` создаёт 1-ю; `retryFailedAttempts` создаёт 2-ю и 3-ю; `expirePastDueSubscriptions` финализирует.

## Восстановление после expire

После `expired` пользователь идёт через `/upgrade`. Это **новый** платёж со стандартным S4-flow:

- Telegram Payments → ЮKassa → списание + сохранение новой карты.
- В `PaymentProcessor` `activatePro` создаёт новый период от `now`, **затирает** старый `savedPaymentMethodId` на новый (через `setSavedPaymentMethodId` в processor).
- `rebillFailedAttempts` сбрасывается в 0.

Не нужно отдельно удалять старую карту через `removePaymentMethod` — ЮKassa сама её зафризит без активности. Если хочется явно — у нас есть `YooKassaApiClient::removePaymentMethod` для будущего S6.

## Зачем тройной retry

Реальные причины фейлов списания:
- **Недостаточно средств** — типично «закинули зарплату через день», retry имеет смысл.
- **Истёкший срок карты** — retry бесполезен, но юзер сам менять карту тоже не побежит. Один retry-цикл закроет подписку и пришлёт явное «нужен новый /upgrade».
- **Технический сбой ЮKassa** — крайне редко, retry почти всегда помогает.

Эмпирически 24 часа × 3 попытки покрывает большинство сценариев без раздражающего «5 раз попытались списать за час».
