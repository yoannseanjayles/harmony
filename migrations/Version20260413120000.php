<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create chat_stream_session table for DB-backed stream state.
 */
final class Version20260413120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chat_stream_session table for DB-backed stream state (replaces filesystem store).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE chat_stream_session (
                stream_id VARCHAR(64) NOT NULL PRIMARY KEY,
                project_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                user_message_id INTEGER NOT NULL,
                status VARCHAR(20) DEFAULT 'pending' NOT NULL,
                assistant_message_id INTEGER DEFAULT NULL,
                events_json CLOB DEFAULT '[]' NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT FK_CSS_PROJECT FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE,
                CONSTRAINT FK_CSS_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX IDX_CSS_PROJECT ON chat_stream_session (project_id)');
        $this->addSql('CREATE INDEX IDX_CSS_USER ON chat_stream_session (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_stream_session');
    }
}
