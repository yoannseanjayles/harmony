<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412190918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T229 — Add thumbKey, previewKey, exportKey variant storage columns to media_asset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_asset ADD COLUMN thumb_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE media_asset ADD COLUMN preview_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE media_asset ADD COLUMN export_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite does not support DROP COLUMN before 3.35 — recreate the table without the variant keys.
        $this->addSql('CREATE TEMPORARY TABLE media_asset_backup AS SELECT id, filename, mime_type, size, storage_key, slide_refs_json, created_at, project_id FROM media_asset');
        $this->addSql('DROP TABLE media_asset');
        $this->addSql('CREATE TABLE media_asset (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, storage_key VARCHAR(255) NOT NULL, slide_refs_json CLOB DEFAULT \'[]\' NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_1DB69EED166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO media_asset SELECT * FROM media_asset_backup');
        $this->addSql('DROP TABLE media_asset_backup');
        $this->addSql('CREATE INDEX IDX_1DB69EED166D1F9C ON media_asset (project_id)');
    }
}
