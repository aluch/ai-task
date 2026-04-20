# Напоминания

Документ описывает архитектуру напоминаний AI Task Agent. **Это центральная функция проекта** — бот должен сам писать пользователю, а не ждать запроса.

## Концепция

Три типа напоминаний, все реализованы:

1. **Приближающийся дедлайн (Тип А)** — за N минут до дедлайна (N задаёт AI или пользователь явно) бот присылает уведомление с кнопками «Сделал / Отложить / Беру в работу».
2. **Периодические напоминания (Тип Б)** — «пинать» задачу без дедлайна каждые N минут, пока пользователь не закроет. Для залежавшихся важных задач.
3. **Пробуждение SNOOZED (Тип В)** — по истечении `snoozedUntil` бот присылает уведомление «задача снова активна» и только после этого переводит задачу в PENDING.

## Модель данных

### Task

- `remindBeforeDeadlineMinutes: ?int` — за сколько минут до дедлайна напомнить. `null` = не напоминать. Ставится AI при парсинге: либо по явной просьбе пользователя (любой priority), либо авто для дедлайна + priority ≥ high.
- `deadlineReminderSentAt: ?\DateTimeImmutable` (TIMESTAMPTZ) — когда уже отправили deadline-уведомление. `null` = ещё не отправлено. После snooze сбрасывается в `null`, чтобы напомнить снова.
- `reminderIntervalMinutes: ?int` — интервал периодических напоминаний в минутах. `null` = не пинать. Ставится AI только для задач **без дедлайна** с priority ≥ high, либо по явной просьбе пользователя. Минимум — 60 минут (AI санитизирует «каждые 5 минут» → 60).
- `lastRemindedAt: ?\DateTimeImmutable` (TIMESTAMPTZ) — скользящее окно для Типа Б. Ставится в момент отправки периодического напоминания. `null` → ещё ни разу не напоминали (но первое напоминание не раньше `createdAt + 60 минут`, чтобы не спамить сразу после создания).
- `snoozedUntil: ?\DateTimeImmutable` (TIMESTAMPTZ) — до какого момента задача в статусе SNOOZED. По истечении — `CheckSnoozeWakeupsHandler` пришлёт уведомление и переведёт в PENDING.

**Методы Task**:
- `markDeadlineReminderSent()` — ставит `deadlineReminderSentAt = now (UTC)`.
- `shouldRemindBeforeDeadline(\DateTimeImmutable $now): bool` — true если deadline + remind_before ≤ now, и ещё не отправляли.
- `setLastRemindedAt(?\DateTimeImmutable)` — используется `sendPeriodicReminder` для сдвига окна.
- `reactivate()` — `status = PENDING`, `snoozedUntil = null`. Вызывается из `sendSnoozeWakeup` после успешной отправки.

### User

- `quietStartHour: int` (default 22) — начало тихих часов в зоне юзера.
- `quietEndHour: int` (default 8).
- `lastMessageAt: ?\DateTimeImmutable` (TIMESTAMPTZ) — когда юзер последний раз писал боту. Обновляется в middleware `HandlerRegistry` через `UserActivityTracker::recordMessage`.

**Методы**:
- `isQuietHoursNow(\DateTimeImmutable $utcNow): bool` — попадает ли текущий момент в тихий интервал в зоне юзера. Поддерживает интервал через полночь (22→8).
- `isRecentlyActive(\DateTimeImmutable $utcNow, int $withinMinutes = 5): bool` — писал ли юзер боту за последние N минут.

## Scheduler архитектура

Symfony Scheduler + Messenger. Один ScheduleProvider с тремя recurring message'ами, ежеминутно:

```
ReminderSchedule (#[AsSchedule('reminders')])
   │ RecurringMessage::every('1 minute', CheckDeadlineRemindersMessage)  ← Тип А
   │ RecurringMessage::every('1 minute', CheckPeriodicRemindersMessage)  ← Тип Б
   │ RecurringMessage::every('1 minute', CheckSnoozeWakeupsMessage)      ← Тип В
   ▼
Messenger transport scheduler_reminders (DSN: doctrine://default)
   │
   ▼
messenger:consume scheduler_reminders  (Docker-сервис `scheduler`, prod env)
   │
   ▼
CheckDeadlineRemindersHandler   → ReminderSender::sendDeadlineReminder
CheckPeriodicRemindersHandler   → ReminderSender::sendPeriodicReminder
CheckSnoozeWakeupsHandler       → ReminderSender::sendSnoozeWakeup
```

**Docker-сервис** `scheduler` запускает `messenger:consume scheduler_reminders --time-limit=3600` в `APP_ENV=prod` с предварительным `cache:warmup`. Prod-режим обязателен — в dev `TraceableEventDispatcher` ломается в worker loop (баг Symfony 7.4). Worker сам завершается через час, Docker рестартит — защита от утечек в long-running процессе.

**Префикс транспорта** `scheduler_` — конвенция Symfony Scheduler: для `#[AsSchedule('reminders')]` ожидается транспорт `scheduler_reminders`.

## ReminderSender

Один класс — три публичных метода. У каждого общие шаги: проверка `telegramId`, проверка quiet hours, проверка recently_active (у разных типов — разные правила), отправка через `TelegramNotifier`, обновление состояния.

### sendDeadlineReminder(Task): SendResult — Тип А

1. Нет `telegramId` → `SKIPPED_NO_CHAT_ID`.
2. Quiet hours → `SKIPPED_QUIET_HOURS`, не помечаем sent.
3. Recently active **с исключением коротких напоминаний**:
   - `remindBeforeDeadlineMinutes < 5` → фильтр не применяется (пользователь сам попросил короткое — блокировать его активным диалогом бессмысленно).
   - Иначе → `SKIPPED_RECENTLY_ACTIVE`, не помечаем sent.
4. Отправка с inline-клавиатурой.
5. Успех → `markDeadlineReminderSent()` + flush → `SENT`.
6. Ошибка → `FAILED`, не помечаем.

### sendPeriodicReminder(Task): SendResult — Тип Б

1. Нет `telegramId` → `SKIPPED_NO_CHAT_ID`.
2. Quiet hours → `SKIPPED_QUIET_HOURS`.
3. Recently active → `SKIPPED_RECENTLY_ACTIVE`. Короткого исключения нет: «пинай каждые 2 часа» не значит «пинай прямо сейчас, я пишу».
4. Текст формата:
   ```
   🔔 Напоминание о задаче:

   📝 Позвонить в страховую
   🔥 high

   Висит уже 2 дня.
   ```
5. Успех → `setLastRemindedAt(now)` + flush → `SENT`. Важно: `lastRemindedAt` — скользящее окно, не флаг «отправлено один раз».

### sendSnoozeWakeup(Task): SendResult — Тип В

1. Нет `telegramId` → `SKIPPED_NO_CHAT_ID`.
2. Quiet hours → `SKIPPED_QUIET_HOURS`. **Задача остаётся SNOOZED** — пробудится только когда сможем уведомить.
3. Recently active **не применяется**: пробуждение — это не автоматический «пинок», а событие, которое пользователь сам запланировал. Отложил до 10:00 — значит в 10:00 разбудить.
4. Текст формата:
   ```
   🔔 Задача снова активна:

   📝 Починить смеситель на даче
   ⏰ дедлайн через 3 дня

   Ты откладывал до 20.04 10:00.
   ```
5. Успех → `reactivate()` (status=PENDING, snoozedUntil=null) + flush → `SENT`.

Во всех трёх случаях — одинаковые три кнопки (см. ниже).

## Фильтр recently_active: исключение для коротких напоминаний

**Зачем**: пользователь пишет «через 3 минуты напомни». AI ставит `remindBeforeDeadlineMinutes=3`. Но пользователь только что писал → `isRecentlyActive(5)` вернёт true → напоминание пропущено, выйдет через 5+ минут, слишком поздно.

**Правило**: если `remindBeforeDeadlineMinutes < 5`, фильтр recently_active не применяется. Пользователь сам заказал короткое напоминание — значит ожидает его получить. Quiet hours продолжают действовать (ночь — это ночь).

**Для Тип Б и Тип В** короткого исключения нет: периодические и разбуживание имеют другую семантику.

## Lazy reactivation снесена

Раньше `SnoozeReactivator::reactivateExpired` вызывался из `TaskRepository::findForUser` перед каждой выборкой. Это работало, пока не было Scheduler'а — но имело проблемы:

- Пользователь узнавал о разбуженной задаче только когда сам открывал `/list` — без уведомления.
- Могло конкурировать с Scheduler'ом (задача «просыпалась» дважды: в findForUser и через tick).

Теперь источник истины для пробуждения — Scheduler. `SnoozeReactivator` удалён, вызовы из `TaskRepository` убраны. Небольшой латенс до минуты (пока тик не произойдёт) — приемлемая цена за явные уведомления.

## TelegramNotifier

`App\Notification\TelegramNotifier::sendMessage(chatId, text, replyMarkup?, parseMode?): bool`.

Тонкая HTTP-обёртка над `https://api.telegram.org/bot<TOKEN>/sendMessage`. Не использует Nutgram — поднимать polling-бот ради одного `sendMessage` избыточно. Ошибки ловятся и возвращают `false`, не ломая worker.

## Callback-кнопки под напоминанием

Три кнопки, одинаковые для всех трёх типов напоминаний:

| Кнопка | Callback | Действие |
|---|---|---|
| ✅ Сделал | `rem:done:<uuid>` | `markDone()` + edit message |
| ⏸ Отложить на час | `rem:snooze1h:<uuid>` | `snooze(+1h)` + **сбрасывает** `deadlineReminderSentAt` — после разбуживания через 1ч Тип А сработает снова, если дедлайн ещё не прошёл |
| 🚀 Беру в работу | `rem:start:<uuid>` | status = IN_PROGRESS + edit message |

Обработчик: `App\Telegram\Handler\ReminderCallbackHandler`, зарегистрирован на `rem:{data}`.

**Про snooze и periodic**: snooze1h НЕ сбрасывает `lastRemindedAt` (в отличие от `deadlineReminderSentAt`). `lastRemindedAt` — скользящее окно, и если пользователь часто откладывает задачу с интервалом «раз в 6 часов», сбрасывание окна превратило бы её в спам.

## TaskParser

JSON-схема включает два поля напоминаний:

- `remind_before_deadline_minutes: int|null` — для Типа А (задача с дедлайном).
- `reminder_interval_minutes: int|null` — для Типа Б (задача без дедлайна).

Правила в system prompt (суть):

**remind_before_deadline_minutes**:
- Если пользователь явно просит («напомни мне за час») — ВСЕГДА ставь, независимо от priority.
- Иначе авто-ставь при `deadline + priority ∈ (high, urgent)` с разумными дефолтами.

**reminder_interval_minutes**:
- Если явная просьба («пинай каждые 2 часа») — ставь интервал из запроса.
- Иначе авто-ставь только для задачи **без дедлайна** с priority urgent (180 мин) или high (360–720 мин).
- Минимум — 60 минут. «Каждые 5 минут» → поднимаем до 60, упоминаем в notes.
- НЕ ставь если у задачи есть дедлайн (она обслуживается Типом А).

Санитизация в `TaskParser::parseResponse`:
- `remindBeforeDeadlineMinutes` принимается если `> 0 && deadline != null`.
- `reminderIntervalMinutes` принимается если `> 0`, с floor до 60.

Оба поля прокидываются в Task через `FreeTextHandler` и `CreateTaskTool`.

## TODO

- **Per-user quiet hours настройка через ассистента** — сейчас глобальный дефолт 22→8, хочется natural language «не беспокой меня после 10».
- **Отдельная сущность `Reminder`**: для множественных напоминаний по одной задаче (за 2 часа + за 30 минут), а не одно поле `remindBeforeDeadlineMinutes`.
- **Учёт `isBlocked()`** — не напоминать о дедлайне задачи, которая сейчас всё равно заблокирована другой. Сложнее — но улучшает UX.
