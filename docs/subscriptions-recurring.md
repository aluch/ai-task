# Renewal flow without recurring

Pro-подписка продлевается **только вручную** через `/upgrade`. Auto-rebill откатан (см. `docs/payments.md` § Почему нет auto-rebill). Этот документ — про UX-стейты и сценарии уведомлений.

## Жизненный цикл подписки

```
Day 0:    user → /upgrade → оплата → Subscription(status=active, period_end=now+30d)
Day 27:   RenewalNotifier → «⏰ Через 3 дня закончится Pro» + /upgrade
Day 29:   RenewalNotifier → «⏰ Завтра закончится Pro» + /upgrade
Day 30+:  RenewalNotifier → «🔚 Подписка Pro закончилась» + expire (status=expired)
Day 30+:  user → /upgrade → новый платёж → Subscription(period_end=now+30d)
                                              ↑
                                  notification_*_renewal_sent_at обнулены
```

В отличие от ситуации с auto-rebill, ничего не списывается без явного действия пользователя.

## Уведомления

Реализованы в `App\Service\Subscription\RenewalNotifier`. Запускается из `RebillScheduler` каждые 15 минут (cron через `ReminderSchedule` → `TriggerRebillMessage` → `TriggerRebillHandler` → `RebillScheduler::run`).

| Trigger | Окно от `currentPeriodEnd` | Дедупликатор | Quiet hours | Текст |
|---|---|---|---|---|
| `notifyThreeDaysBefore` | `now+60h..now+72h` | `notification_3d_renewal_sent_at` | да | «⏰ Через 3 дня закончится Pro» |
| `notifyOneDayBefore` | `now+12h..now+24h` | `notification_1d_renewal_sent_at` | да | «⏰ Завтра закончится Pro» |
| `notifyExpiredAndExpire` | `currentPeriodEnd < now` | `notification_expired_sent_at` | нет | «🔚 Подписка Pro закончилась» + перевод в Expired |

Окна шире 15-минутного шага scheduler'а (60-72h, 12-24h), чтобы переживать пропуск тика. Дубль защищён `_sent_at`-флагом.

### Quiet hours

Для 3д/1д соблюдаются: если попадаем в ночь — пропускаем тик, на следующем 15-минутном проходе попробуем снова. Для expired — нет: свершившийся факт, ночью или нет, перевести надо.

### Фильтр «не трогать триал»

Триалы обрабатываются `TrialNotifier` со своими полями (`notification_3d_sent_at` / `_1d_sent_at` привязаны к `trialEndsAt`). `RenewalNotifier` фильтрует по `plan=Pro AND trial_ends_at IS NULL` — кросс-уведомления невозможны.

### Переиспользование `notification_expired_sent_at`

Это поле было в S2 для триальных expired-уведомлений. После отката S5 переиспользуется для paid-Pro renewal. Чтобы цикл «trial → Pro → expired Pro» сработал корректно, `SubscriptionService::activatePro` обнуляет три renewal-флага (`notification_3d_renewal_sent_at`, `notification_1d_renewal_sent_at`, `notification_expired_sent_at`) при переводе подписки в Active.

## UI: /subscription для active Pro

```
💎 Pro

Статус: активна
Истекает: 11.06.2026 (через 27 дней)
Использовано в этом месяце: 47 / 1500

[💎 Продлить сейчас]
```

Кнопка «💎 Продлить сейчас» → callback `subscription:renew` → `SubscriptionCallbackHandler::handleRenew` → `UpgradeCallbackHandler::sendRenewalInvoice(bot, user)`.

`sendRenewalInvoice` отличается от обычного `handlePay` тем что **bypass'ит** проверку «уже active Pro» — это явное намерение продлить досрочно. После успешной оплаты `SuccessfulPaymentHandler` → `PaymentProcessor::process` → `SubscriptionService::activatePro` сдвигает `currentPeriodEnd` и обнуляет renewal-флаги для нового цикла.

Если пользователь продлевает за день до истечения, новый период всё равно отсчитывается от `now`, а не от старого `currentPeriodEnd`. Это потеря ~1 дня для пользователя, но проще логически и не требует «остатков». Рассмотрим суммирование если кто-то пожалуется.

## UI: /subscription для других стейтов

- **Trial** — без изменений, как в S3. `TrialNotifier` шлёт 3д/1д/expired через свои поля.
- **Cancelled** — UI остался, на практике не используется (hard-cancel удалён в commit 1, status=Cancelled может возникнуть только через прямой вызов `SubscriptionService::cancel`).
- **Free / Expired** — кнопка «💎 Узнать про Pro» → `/upgrade`.

## Что НЕ происходит

- ❌ Автоматическое списание перед истечением.
- ❌ Уведомление «завтра спишется 490 ₽» (теперь «завтра закончится»).
- ❌ Попытки retry'ев списания.
- ❌ Перевод в `past_due`.

Webhook endpoint `/api/yookassa/webhook` и связанная инфраструктура (`RebillWebhookProcessor`, `RecurringAttempt`, `YooKassaApiClient::createRecurringPayment`) — на месте как dead code. Webhook может пригодиться в S6 для refund-уведомлений от ЮKassa.

## Когда вернётся auto-rebill

Когда Telegram Payments начнёт пробрасывать `save_payment_method` в ЮKassa, или мы перейдём на ЮKassa Checkout / Telegram Stars. См. `docs/payments.md`.

## Smoke

- `s5-no-rebill-3d-notification` — currentPeriodEnd через 65 часов → уведомление + флаг.
- `s5-no-rebill-1d-notification` — currentPeriodEnd через 18 часов → уведомление + флаг.
- `s5-no-rebill-expired-message` — currentPeriodEnd < now → текст «закончилась» + Expired.
- `s5-subscription-renew-button-redirects-to-upgrade` — кнопка отдаёт callback `subscription:renew`, `UpgradeCallbackHandler::sendRenewalInvoice` существует.
