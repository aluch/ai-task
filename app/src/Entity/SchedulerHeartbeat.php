<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton-таблица: один ряд (id=1), который scheduler обновляет
 * на каждом tick'е. /health-эндпоинт сравнивает last_tick_at с now;
 * если разница >180 сек — scheduler помечается stale и /health
 * возвращает 503 (Uptime Robot триггерит алерт).
 *
 * CHECK constraint в миграции гарантирует что id=1 единственный.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scheduler_heartbeat')]
class SchedulerHeartbeat
{
    #[ORM\Id]
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $id = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastTickAt;

    public function __construct()
    {
        $this->lastTickAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getLastTickAt(): \DateTimeImmutable
    {
        return $this->lastTickAt;
    }
}
