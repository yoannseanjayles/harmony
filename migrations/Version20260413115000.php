<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add status column to chat_message for async generation tracking.
 */
final class Version20260413115000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status column to chat_message (pending/generating/done/error) for async generation tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE chat_message ADD COLUMN status VARCHAR(20) DEFAULT 'done' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // SQLite does not support DROP COLUMN directly; recreate table without the column.
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_message AS SELECT id, project_id, role, content, created_at FROM chat_message');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, role VARCHAR(20) NOT NULL, content CLOB NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_FAB3FC16166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_message (id, project_id, role, content, created_at) SELECT id, project_id, role, content, created_at FROM __temp__chat_message');
        $this->addSql('DROP TABLE __temp__chat_message');
        $this->addSql('CREATE INDEX IDX_FAB3FC16166D1F9C ON chat_message (project_id)');
    }
}
