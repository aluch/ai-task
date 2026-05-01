<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Persistence\ManagerRegistry;

/**
 * Liveness-индикатор для scheduler'а: на каждом tick'е (любой из 4
 * reminder-handler'ов) дёргает recordTick(), HealthController в /health
 * проверяет через isStale().
 *
 * Используем DBAL UPDATE напрямую (а не ORM): операция вызывается часто
 * (раз в минуту), не нужны hydrator'ы и identity map.
 */
class HeartbeatTracker
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function recordTick(\DateTimeImmutable $now): void
    {
        $this->doctrine->getConnection()->executeStatement(
            'UPDATE scheduler_heartbeat SET last_tick_at = :now WHERE id = 1',
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP')],
        );
    }

    public function getLastTick(): ?\DateTimeImmutable
    {
        $raw = $this->doctrine->getConnection()->fetchOne(
            'SELECT last_tick_at FROM scheduler_heartbeat WHERE id = 1',
        );
        if ($raw === false || $raw === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param int $thresholdSeconds 180 = 3 минуты. Scheduler tick'ает каждую
     *   минуту, 3 минуты молчания — точно что-то сломано.
     */
    public function isStale(\DateTimeImmutable $now, int $thresholdSeconds = 180): bool
    {
        $last = $this->getLastTick();
        if ($last === null) {
            return true;
        }

        return ($now->getTimestamp() - $last->getTimestamp()) > $thresholdSeconds;
    }
}
