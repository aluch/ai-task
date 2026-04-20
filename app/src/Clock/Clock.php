<?php

declare(strict_types=1);

namespace App\Clock;

/**
 * Источник «текущего момента» для кода, который хочет быть тестируемым.
 * Прод-реализация (`SystemClock`) возвращает реальное UTC-now, smoke-тесты
 * подменяют на `FrozenClock` чтобы зафиксировать/двигать время вручную.
 *
 * Не обязаны использовать все места — только те, где критично подменять
 * время для сценарного теста (ReminderSender, scheduler handlers). Обычные
 * команды / HTTP-контроллеры могут продолжать писать `new DateTimeImmutable`.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
