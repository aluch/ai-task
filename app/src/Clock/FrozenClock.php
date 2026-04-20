<?php

declare(strict_types=1);

namespace App\Clock;

/**
 * Для smoke-тестов: фиксирует «сейчас» в заданной точке и позволяет
 * двигать его вручную. Инжектится вместо `SystemClock` внутри smoke-команд
 * через `Clock::class` alias override.
 */
final class FrozenClock implements Clock
{
    public function __construct(
        private \DateTimeImmutable $frozen,
    ) {
    }

    public static function atUtc(string $isoOrRelative): self
    {
        $dt = new \DateTimeImmutable($isoOrRelative);

        return new self($dt->setTimezone(new \DateTimeZone('UTC')));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->frozen;
    }

    public function setTo(\DateTimeImmutable $t): void
    {
        $this->frozen = $t->setTimezone(new \DateTimeZone('UTC'));
    }

    public function advance(string $duration): void
    {
        $this->frozen = $this->frozen->modify($duration);
    }
}
