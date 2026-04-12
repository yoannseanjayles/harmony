<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T235 — Add media_refs_json column to the slide table.
 *
 * Tracks the IDs of MediaAsset records referenced by each slide so that
 * SlideBuilder can resolve signed URLs at render time (T237).
 */
final class Version20260412192622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T235 — Add media_refs_json column to slide for tracking referenced media assets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE slide ADD COLUMN media_refs_json CLOB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // SQLite does not support DROP COLUMN before 3.35 — recreate without the new column.
        $this->addSql('CREATE TEMPORARY TABLE slide_backup AS SELECT id, project_id, type, content_json, position, render_hash, html_cache FROM slide');
        $this->addSql('DROP TABLE slide');
        $this->addSql("CREATE TABLE slide (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, type VARCHAR(30) NOT NULL, content_json CLOB DEFAULT '{}' NOT NULL, position INTEGER NOT NULL, render_hash VARCHAR(64) DEFAULT NULL, html_cache CLOB DEFAULT NULL, CONSTRAINT FK_72B682D6166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('INSERT INTO slide SELECT * FROM slide_backup');
        $this->addSql('DROP TABLE slide_backup');
        $this->addSql('CREATE INDEX IDX_72B682D6166D1F9C ON slide (project_id)');
    }
}
