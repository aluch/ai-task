<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415190533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE task_contexts (id UUID NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EC9124F977153098 ON task_contexts (code)');
        $this->addSql('CREATE TABLE tasks (id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, deadline TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, estimated_minutes INT DEFAULT NULL, priority VARCHAR(16) DEFAULT \'medium\' NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, source VARCHAR(16) DEFAULT \'manual\' NOT NULL, source_ref VARCHAR(255) DEFAULT NULL, reminder_interval_minutes INT DEFAULT NULL, last_reminded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_50586597A76ED395 ON tasks (user_id)');
        $this->addSql('CREATE INDEX idx_tasks_user_status ON tasks (user_id, status)');
        $this->addSql('CREATE INDEX idx_tasks_deadline ON tasks (deadline)');
        $this->addSql('CREATE TABLE task_context_link (task_id UUID NOT NULL, context_id UUID NOT NULL, PRIMARY KEY (task_id, context_id))');
        $this->addSql('CREATE INDEX IDX_3751F9ED8DB60186 ON task_context_link (task_id)');
        $this->addSql('CREATE INDEX IDX_3751F9ED6B00C1CF ON task_context_link (context_id)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, telegram_id BIGINT DEFAULT NULL, name VARCHAR(120) DEFAULT NULL, timezone VARCHAR(64) DEFAULT \'Europe/Tallinn\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9CC0B3066 ON users (telegram_id)');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_context_link ADD CONSTRAINT FK_3751F9ED8DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_context_link ADD CONSTRAINT FK_3751F9ED6B00C1CF FOREIGN KEY (context_id) REFERENCES task_contexts (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tasks DROP CONSTRAINT FK_50586597A76ED395');
        $this->addSql('ALTER TABLE task_context_link DROP CONSTRAINT FK_3751F9ED8DB60186');
        $this->addSql('ALTER TABLE task_context_link DROP CONSTRAINT FK_3751F9ED6B00C1CF');
        $this->addSql('DROP TABLE task_contexts');
        $this->addSql('DROP TABLE tasks');
        $this->addSql('DROP TABLE task_context_link');
        $this->addSql('DROP TABLE users');
    }
}
