<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412035014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add archive and structured content fields to project for feature 6';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD COLUMN slides_json CLOB NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE project ADD COLUMN theme_config_json CLOB NOT NULL DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE project ADD COLUMN metadata_json CLOB NOT NULL DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE project ADD COLUMN media_refs_json CLOB NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE project ADD COLUMN archived_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__project AS SELECT id, title, provider, model, status, created_at, updated_at, user_id FROM project');
        $this->addSql('DROP TABLE project');
        $this->addSql('CREATE TABLE project (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(160) NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_2FB3D0EEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project (id, title, provider, model, status, created_at, updated_at, user_id) SELECT id, title, provider, model, status, created_at, updated_at, user_id FROM __temp__project');
        $this->addSql('DROP TABLE __temp__project');
        $this->addSql('CREATE INDEX IDX_2FB3D0EEA76ED395 ON project (user_id)');
    }
}
