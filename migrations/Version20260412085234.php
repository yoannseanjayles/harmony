<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412085234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add signed sharing fields to project for feature 8';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project ADD COLUMN share_token VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD COLUMN share_expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD COLUMN is_public BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__project AS SELECT id, title, provider, model, status, slides_json, theme_config_json, metadata_json, media_refs_json, created_at, updated_at, archived_at, user_id FROM project');
        $this->addSql('DROP TABLE project');
        $this->addSql('CREATE TABLE project (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(160) NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, slides_json CLOB DEFAULT \'[]\' NOT NULL, theme_config_json CLOB DEFAULT \'{}\' NOT NULL, metadata_json CLOB DEFAULT \'{}\' NOT NULL, media_refs_json CLOB DEFAULT \'[]\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_2FB3D0EEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project (id, title, provider, model, status, slides_json, theme_config_json, metadata_json, media_refs_json, created_at, updated_at, archived_at, user_id) SELECT id, title, provider, model, status, slides_json, theme_config_json, metadata_json, media_refs_json, created_at, updated_at, archived_at, user_id FROM __temp__project');
        $this->addSql('DROP TABLE __temp__project');
        $this->addSql('CREATE INDEX IDX_2FB3D0EEA76ED395 ON project (user_id)');
    }
}
