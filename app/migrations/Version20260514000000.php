<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S3 — converted_from_trial_at в subscriptions. Заполняется при
 * activatePro(), если предыдущая подписка была триалом. Используется
 * /admin stats для метрики «конверсия триал → Pro».
 *
 * Nullable, безопасно к существующим строкам (NULL = «не было перехода»).
 */
final class Version20260514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S3: subscriptions.converted_from_trial_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions ADD converted_from_trial_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP converted_from_trial_at');
    }
}
