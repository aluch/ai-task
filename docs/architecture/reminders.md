# Напоминания

Документ описывает архитектуру напоминаний AI Task Agent. **Это центральная функция проекта** — бот должен сам писать пользователю, а не ждать запроса.

## Концепция

Планируются три типа напоминаний:

1. **Приближающийся дедлайн** — *реализовано в 5.1*. За N минут до дедлайна (N задаёт AI при парсинге) бот присылает сообщение с кнопками «Сделал / Отложить / Беру в работу».
2. **Разбуживание SNOOZED** — *отложено до 5.2*. Сейчас задача сама возвращается в PENDING через `SnoozeReactivator` при следующей выборке. Хочется активного уведомления.
3. **Периодические напоминания** — *отложено до 5.2*. `reminderIntervalMinutes` + `lastRemindedAt` уже в модели, но логика не включена.

## Модель данных

### Task

- `remindBeforeDeadlineMinutes: ?int` — за сколько минут до дедлайна напомнить. `null` = не напоминать. Ставится AI при парсинге только для задач с дедлайном и приоритетом ≥ high.
- `deadlineReminderSentAt: ?\DateTimeImmutable` (TIMESTAMPTZ) — когда уже отправили уведомление, чтобы не слать повторно. `null` = ещё не отправлено.

**Методы**:
- `markDeadlineReminderSent()` — устанавливает `deadlineReminderSentAt = now (UTC)`.
- `shouldRemindBeforeDeadline(\DateTimeImmutable $now): bool` — true если deadline + remind_before ≤ now, и ещё не отправляли.

### User

- `quietStartHour: int` (default 22) — начало тихих часов в зоне юзера.
- `quietEndHour: int` (default 8).
- `lastMessageAt: ?\DateTimeImmutable` (TIMESTAMPTZ) — когда юзер последний раз писал боту. Обновляется в middleware `HandlerRegistry` через `UserActivityTracker::recordMessage`.

**Методы**:
- `isQuietHoursNow(\DateTimeImmutable $utcNow): bool` — попадает ли текущий момент в тихий интервал в зоне юзера. Поддерживает интервал через полночь (22→8).
- `isRecentlyActive(\DateTimeImmutable $utcNow, int $withinMinutes = 5): bool` — писал ли юзер боту за последние N минут.

## Scheduler архитектура

Используется Symfony Scheduler + Messenger.

```
DeadlineReminderSchedule (#[AsSchedule('reminders')])
   │ RecurringMessage::every('1 minute', CheckDeadlineRemindersMessage)
   ▼
Messenger transport scheduler_reminders (DSN: doctrine://default)
   │
   ▼
messenger:consume scheduler_reminders  (Docker-сервис `scheduler`)
   │
   ▼
CheckDeadlineRemindersHandler
   │ TaskRepository::findDeadlineReminderCandidates($now)
   │ foreach task → ReminderSender::sendDeadlineReminder($task)
```

**Docker-сервис** `scheduler` запускает `messenger:consume scheduler_reminders --time-limit=3600`. Worker сам завершается через час, Docker рестартит — защита от утечек в long-running процессе.

**Префикс транспорта** `scheduler_` — конвенция Symfony Scheduler: для `#[AsSchedule('reminders')]` ожидается транспорт `scheduler_reminders`.

## ReminderSender

`App\Notification\ReminderSender::sendDeadlineReminder(Task): SendResult`.

### Алгоритм

1. Проверка `telegramId` — `SKIPPED_NO_CHAT_ID` если пусто
2. Проверка `user->isQuietHoursNow()` → **SKIPPED_QUIET_HOURS**, не помечаем sent (после выхода из тихих часов Scheduler попробует снова)
3. Проверка `user->isRecentlyActive()` → **SKIPPED_RECENTLY_ACTIVE** (активный диалог — незачем дублировать), не помечаем
4. Отправка через `TelegramNotifier::sendMessage()` с inline-клавиатурой
5. При успехе → `task->markDeadlineReminderSent()` + flush → **SENT**
6. При ошибке → логируем, НЕ помечаем sent → **FAILED** (Scheduler попробует снова через минуту)

### Quiet hours

**Зачем**: не будить пользователя ночью. Текущий дефолт — 22:00-08:00 в локальной зоне.

**Механика**: `User::isQuietHoursNow()` конвертирует UTC-момент в зону юзера, проверяет попадание в `[quietStartHour, quietEndHour)`. Если `start > end` (интервал через полночь, как 22→8) — логика «час ≥ start ИЛИ час < end».

**Пропущенные напоминания**: не помечаем `deadlineReminderSentAt`. При следующем тике Scheduler снова увидит кандидата — пока quiet hours, снова SKIPPED. Как только время вышло — SENT.

### Recently active

**Зачем**: если юзер только что писал боту, нет смысла слать уведомление с другой стороны диалога. Дождёмся паузы.

**Механика**: `User::lastMessageAt` обновляется в middleware `HandlerRegistry` на каждом update'е через `UserActivityTracker::recordMessage`. `isRecentlyActive(utcNow, 5)` → разница ≤ 5 минут.

Не помечаем sent. Scheduler попробует через минуту, потом ещё через минуту — пока активен, пропускаем. Как пауза 5+ минут — отправляем.

## TelegramNotifier

`App\Notification\TelegramNotifier::sendMessage(chatId, text, replyMarkup?, parseMode?): bool`.

Тонкая HTTP-обёртка над `https://api.telegram.org/bot<TOKEN>/sendMessage`. Не использует Nutgram — поднимать polling-бот ради одного `sendMessage` избыточно. Ошибки ловятся и возвращают `false`, не ломая worker.

Для сообщений **внутри** polling-цикла handlers'ы бота продолжают использовать Nutgram. TelegramNotifier — только для side-channel уведомлений (Scheduler, будущие Messenger workers).

## Callback-кнопки под напоминанием

Три кнопки в клавиатуре:

| Кнопка | Callback | Действие |
|---|---|---|
| ✅ Сделал | `rem:done:<uuid>` | `markDone()` + edit message |
| ⏸ Отложить на час | `rem:snooze1h:<uuid>` | `snooze(+1h)` + **сбрасывает** `deadlineReminderSentAt` — после разбуживания напомнит снова, если дедлайн ещё не прошёл |
| 🚀 Беру в работу | `rem:start:<uuid>` | status = IN_PROGRESS + edit message |

Обработчик: `App\Telegram\Handler\ReminderCallbackHandler`, зарегистрирован на `rem:{data}`.

## TaskParser

В JSON-схему добавлено поле `remind_before_deadline_minutes` (int или null). Правило в system prompt:

```
Ставь ТОЛЬКО если:
  - есть deadline
  - priority = high или urgent
Иначе — null.

Рекомендации:
  - urgent + дедлайн сегодня → 30
  - high + дедлайн сегодня → 60
  - high + дедлайн завтра+ → 120
  - urgent + дедлайн завтра+ → 60
  - Если estimated_minutes > 60 — увеличь, чтобы успеть начать
```

В `ParsedTaskDTO::$remindBeforeDeadlineMinutes` + поле санитизируется в `TaskParser::parseResponse` (не ставим если нет deadline или приоритет medium/low — даже если AI проигнорировал правило).

Прокидывается в Task через `FreeTextHandler` и `CreateTaskTool`.

## TODO (5.2+)

- **Разбуживание SNOOZED**: отдельный Schedule или расширение существующего для задач с `snoozedUntil <= now`.
- **Периодические напоминания**: `reminderIntervalMinutes` + `lastRemindedAt` — каждые N минут для залипших задач.
- **Per-user quiet hours настройка через ассистента** — сейчас глобальный дефолт 22→8, хочется natural language «не беспокой меня после 10».
- **Отдельная сущность `Reminder`**: для множественных напоминаний по одной задаче (за 2 часа + за 30 минут), а не одно поле `remindBeforeDeadlineMinutes`.
- **Учёт `isBlocked()`** — не напоминать о дедлайне задачи, которая сейчас всё равно заблокирована другой. Сложнее — но улучшает UX.
