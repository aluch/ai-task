<?php

declare(strict_types=1);

namespace App\Smoke;

final class ScenarioResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $message,
        public readonly float $elapsedSeconds,
    ) {
    }

    public static function pass(string $name, float $elapsed, string $message = 'ok'): self
    {
        return new self($name, true, $message, $elapsed);
    }

    public static function fail(string $name, float $elapsed, string $message): self
    {
        return new self($name, false, $message, $elapsed);
    }
}
