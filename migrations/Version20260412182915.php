<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412182915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T209 — Add media_asset table for MediaAsset entity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media_asset (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, storage_key VARCHAR(255) NOT NULL, slide_refs_json CLOB DEFAULT \'[]\' NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_1DB69EED166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1DB69EED166D1F9C ON media_asset (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_asset');
    }
}
