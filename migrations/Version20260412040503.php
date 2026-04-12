<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412040503 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project_version table for feature 7 version history';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_version (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, version_number INTEGER NOT NULL, snapshot_json CLOB DEFAULT \'{}\' NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_2902DFA6166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2902DFA6166D1F9C ON project_version (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_version_number ON project_version (project_id, version_number)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE project_version');
    }
}
