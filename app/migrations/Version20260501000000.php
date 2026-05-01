<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Singleton-таблица scheduler_heartbeat: один ряд (id=1), scheduler
 * обновляет last_tick_at на каждом tick'е. /health использует это
 * чтобы понять что scheduler жив.
 *
 * CHECK (id=1) — гарантия что других строк не появится. INSERT в up()
 * обязателен: без начальной строки UPDATE из HeartbeatTracker ничего
 * не сделал бы (нет cтроки → no-op), и /health всегда видел бы stale.
 */
final class Version20260501000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduler_heartbeat singleton table for /health liveness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduler_heartbeat (
            id SMALLINT PRIMARY KEY DEFAULT 1,
            last_tick_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            CONSTRAINT scheduler_heartbeat_singleton CHECK (id = 1)
        )');
        $this->addSql('INSERT INTO scheduler_heartbeat (id, last_tick_at) VALUES (1, NOW())');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduler_heartbeat');
    }
}
