<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412113700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create slide table for HRM-F16 — Slide entity (type, contentJson, position, renderHash, htmlCache)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE slide (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, type VARCHAR(30) NOT NULL, content_json CLOB DEFAULT \'{}\' NOT NULL, position INTEGER NOT NULL, render_hash VARCHAR(64) DEFAULT NULL, html_cache CLOB DEFAULT NULL, CONSTRAINT FK_72EFEE62166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_72EFEE62166D1F9C ON slide (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE slide');
    }
}
