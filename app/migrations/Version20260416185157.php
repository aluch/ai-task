<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416185157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task_dependencies join table for task blocking (ManyToMany self-ref)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE task_dependencies (blocked_task_id UUID NOT NULL, blocker_task_id UUID NOT NULL, PRIMARY KEY (blocked_task_id, blocker_task_id))');
        $this->addSql('CREATE INDEX IDX_229E54A034646970 ON task_dependencies (blocked_task_id)');
        $this->addSql('CREATE INDEX IDX_229E54A0EBBE528B ON task_dependencies (blocker_task_id)');
        $this->addSql('ALTER TABLE task_dependencies ADD CONSTRAINT FK_229E54A034646970 FOREIGN KEY (blocked_task_id) REFERENCES tasks (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_dependencies ADD CONSTRAINT FK_229E54A0EBBE528B FOREIGN KEY (blocker_task_id) REFERENCES tasks (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task_dependencies DROP CONSTRAINT FK_229E54A034646970');
        $this->addSql('ALTER TABLE task_dependencies DROP CONSTRAINT FK_229E54A0EBBE528B');
        $this->addSql('DROP TABLE task_dependencies');
    }
}
