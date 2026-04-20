<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260420035546 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deadline reminders fields to tasks and quiet hours / last activity to users';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tasks ADD remind_before_deadline_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD deadline_reminder_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD quiet_start_hour SMALLINT DEFAULT 22 NOT NULL');
        $this->addSql('ALTER TABLE users ADD quiet_end_hour SMALLINT DEFAULT 8 NOT NULL');
        $this->addSql('ALTER TABLE users ADD last_message_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tasks DROP remind_before_deadline_minutes');
        $this->addSql('ALTER TABLE tasks DROP deadline_reminder_sent_at');
        $this->addSql('ALTER TABLE users DROP quiet_start_hour');
        $this->addSql('ALTER TABLE users DROP quiet_end_hour');
        $this->addSql('ALTER TABLE users DROP last_message_at');
    }
}
