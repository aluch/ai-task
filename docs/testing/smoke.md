# Smoke-тесты

Быстрая самопроверка основных сценариев бота через Symfony Console
команды — **не замена ручных проверок через Telegram**, а дополнение
для частых итераций «поменял → прогнал → увидел результат».

## Философия

- Это **не PHPUnit-тесты**. Мы работаем с реальной БД разработки, реально
  дергаем Claude API для нескольких команд (`app:smoke:parser`,
  `app:smoke:assistant`, `app:smoke:advisor`) и используем реальный
  Symfony DI-контейнер.
- Изоляция — через **тестового пользователя** `telegram_id=999999999`.
  Все задачи этого юзера — в зоне ответственности smoke-команд, они
  чистят его БД перед каждым прогоном. Реальные пользователи не
  затрагиваются.
- Telegram Bot API **не дергается**. `TelegramNotifier` в runtime
  переключается в in-memory режим через `useInMemory()` — сообщения
  сохраняются в массив, реальных HTTP-запросов нет.
- Время **можно замораживать** через `FrozenClock` для детерминизма
  (чтобы не ждать 65 минут прежде чем проверить «первое периодическое
  напоминание через час после создания»).

## Абстракции

Две новые абстракции для мокабельности:

- **`App\Clock\Clock`** (interface `now(): \DateTimeImmutable`) с двумя
  реализациями: `SystemClock` (прод, реальное UTC-now) и `FrozenClock`
  (smoke, замороженное время + `advance()` / `setTo()`).
  Инжектится в: `ReminderSender`, три scheduler handler'а
  (`CheckDeadlineRemindersHandler`, `CheckPeriodicRemindersHandler`,
  `CheckSnoozeWakeupsHandler`), `UserActivityTracker`. Остальные
  места оставлены на потом — большинство smoke-сценариев касаются
  reminder pipeline.

- **`App\Notification\TelegramNotifierInterface`** — прод
  `TelegramNotifier` (HTTP) и smoke `InMemoryTelegramNotifier`.
  `TelegramNotifier` имеет метод `useInMemory(InMemoryTelegramNotifier)`,
  который в runtime переключает его в режим делегирования отправок
  в память. Прод-код не знает об этом — просто продолжает работать
  через интерфейс.

Подмена сервисов в smoke-командах — **reflection-swap** через
`SmokeHarness::swapClockIn()`: свойство `$clock` помечено не-`readonly`
в сервисах reminder pipeline (PHP 8.3 не даёт менять readonly даже
через reflection). `container->set()` не используется — Symfony 7
запрещает замену уже-инициализированных сервисов.

## Команды

Все команды — с префиксом `app:smoke:`, разворачиваются внутри
php-контейнера. В Makefile есть удобные shortcuts.

### `app:smoke:reset` — сброс тестового юзера

```bash
make smoke-reset
# или: php bin/console app:smoke:reset
```

Удаляет тестового пользователя и все его задачи. Никаких сайд-эффектов
на других пользователей.

### `app:smoke:parser <text>` — DTO-срез TaskParser

```bash
make smoke-parser msg="Напоминай каждые 30 минут попить воды"
```

Гоняет `TaskParser::parse()` без сохранения в БД. Полезно когда меняешь
system prompt и хочешь проверить что AI понимает задачу правильно.

### `app:smoke:assistant <message>` — прогон Assistant

```bash
make smoke-assistant msg="Купить молоко завтра"
```

Отправляет сообщение от имени тестового юзера в `Assistant::handle()`,
показывает:
- Reply text (что бы увидел пользователь)
- Tools called
- Iterations + token usage
- Созданные/изменённые задачи в БД

Флаги (доступны через прямой вызов `app:smoke:assistant`, не через make):
- `--keep` — не делать reset перед (для цепочки тестов).
- `--now="ISO"` — зафиксировать «текущее» время.

### `app:smoke:advisor <minutes> [context]` — прогон TaskAdvisor

Предполагает что у тестового юзера уже есть задачи (создай через
несколько `app:smoke:assistant --keep` сначала).

```bash
php bin/console app:smoke:advisor 60 "дома с ноутбуком"
```

### `app:smoke:reminder-tick [--type=deadline|periodic|snooze|all]`

Вручную прогоняет один tick scheduler handler'а. По умолчанию
фильтрует только задачи тестового юзера (`--include-real-users`
снимает фильтр для отладки prod-данных).

```bash
make smoke-tick
# или с типом:
php bin/console app:smoke:reminder-tick --type=deadline
```

### `app:smoke:reminder-scenario <name>` — один сценарий

```bash
make smoke-scenario name=deadline-short
```

Известные сценарии:
| Имя | Что проверяет |
|---|---|
| `deadline-short` | базовое deadline-напоминание → SENT, поставлен `deadline_reminder_sent_at` |
| `deadline-quiet-hours` | в тихие часы → SKIPPED_QUIET_HOURS, sent НЕ ставится |
| `deadline-recently-active` | пользователь писал 2 мин назад → SKIPPED_RECENTLY_ACTIVE. Прошло 6 мин → SENT |
| `deadline-short-ignores-recent` | `remind_before<5` игнорирует recently_active → SENT |
| `periodic-first-reminder` | первое периодическое через `createdAt+60min` → SENT |
| `periodic-in-progress-doubled` | IN_PROGRESS удваивает эффективный интервал (x2) |
| `snooze-wakeup-success` | SNOOZED задача разбужена → SENT + PENDING |
| `snooze-wakeup-quiet-hours` | разбудить в тихие часы → SKIPPED, статус остаётся SNOOZED |

### `app:smoke:all` — всё подряд

```bash
make smoke-all
```

Прогоняет все 8 сценариев, печатает сводку `N passed, M failed`.
Exit code 0 только если все ✅ — удобно для автоматизации.

После прогона автоматически делает reset — в БД не остаётся мусора.

## Добавление нового сценария

1. Открой `App\Smoke\ScenarioRunner`.
2. Добавь метод `private function <name>(): ScenarioResult` с логикой:
   - `$this->setupFresh($nowIso, quietHours: [22, 8])` — сбрасывает БД
     и возвращает User + зафиксированный now.
   - `$this->createTask($user, $title, [...])` — создать задачу с
     нужными полями (включая `createdAt` через reflection, если нужно
     сдвинуть в прошлое).
   - `$this->sender->send*Reminder($task)` — прогнать sender.
   - Проверки: статус задачи через `$this->harness->refreshTask($id)`,
     количество сообщений через `$this->harness->notifier()->count()`.
   - Вернуть `ScenarioResult::pass(...)` или `ScenarioResult::fail(...)`.
3. Зарегистрировать имя в `$this->scenarios` (массив в конструкторе).
4. Добавить строку в таблицу в этом документе.

## Когда что использовать

| Ситуация | Команда |
|---|---|
| Поменял system prompt парсера | `make smoke-parser msg="..."` |
| Поменял system prompt ассистента | `make smoke-assistant msg="..."` |
| Поменял логику TaskAdvisor | `smoke-assistant --keep` × N → `smoke-advisor` |
| Поменял ReminderSender / handlers / расчёт quiet hours | `make smoke-all` |
| Хочу понять почему scheduler что-то не шлёт | `make smoke-tick` |
| Большой рефакторинг — хочу убедиться что ничего не сломал | `make smoke-all` (должно быть 8/8 ✅) |
| Финальная проверка перед коммитом | `make smoke-all` |

**Live-тесты через Telegram** — для «живой» проверки UX (кнопки,
форматирование, последовательность сообщений, реальные тайминги
scheduler'а) — делай раз в день / перед релизом, не чаще.
