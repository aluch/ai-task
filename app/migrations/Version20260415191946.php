<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415191946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert all datetime columns to TIMESTAMPTZ; add tasks.snoozed_until';
    }

    /**
     * До этой миграции PHP писал naive timestamps: date.timezone в php.ini = Europe/Tallinn,
     * поэтому существующие значения в БД физически представляют локальное время Tallinn.
     * USING ... AT TIME ZONE 'Europe/Tallinn' переинтерпретирует их и сохранит тот же
     * абсолютный момент времени в UTC внутри TIMESTAMPTZ.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tasks ADD snoozed_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql("ALTER TABLE tasks ALTER deadline TYPE TIMESTAMP(0) WITH TIME ZONE USING deadline AT TIME ZONE 'Europe/Tallinn'");
        $this->addSql("ALTER TABLE tasks ALTER last_reminded_at TYPE TIMESTAMP(0) WITH TIME ZONE USING last_reminded_at AT TIME ZONE 'Europe/Tallinn'");
        $this->addSql("ALTER TABLE tasks ALTER completed_at TYPE TIMESTAMP(0) WITH TIME ZONE USING completed_at AT TIME ZONE 'Europe/Tallinn'");
        $this->addSql("ALTER TABLE tasks ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE USING created_at AT TIME ZONE 'Europe/Tallinn'");
        $this->addSql("ALTER TABLE tasks ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE USING updated_at AT TIME ZONE 'Europe/Tallinn'");
        $this->addSql("ALTER TABLE users ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE USING created_at AT TIME ZONE 'Europe/Tallinn'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tasks ALTER deadline TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING (deadline AT TIME ZONE 'Europe/Tallinn')");
        $this->addSql("ALTER TABLE tasks ALTER last_reminded_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING (last_reminded_at AT TIME ZONE 'Europe/Tallinn')");
        $this->addSql("ALTER TABLE tasks ALTER completed_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING (completed_at AT TIME ZONE 'Europe/Tallinn')");
        $this->addSql("ALTER TABLE tasks ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING (created_at AT TIME ZONE 'Europe/Tallinn')");
        $this->addSql("ALTER TABLE tasks ALTER updated_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING (updated_at AT TIME ZONE 'Europe/Tallinn')");
        $this->addSql("ALTER TABLE users ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING (created_at AT TIME ZONE 'Europe/Tallinn')");
        $this->addSql('ALTER TABLE tasks DROP snoozed_until');
    }
}
