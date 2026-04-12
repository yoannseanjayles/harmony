<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412104042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align project metric tables with Doctrine SQLite schema metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__project_export_metric AS SELECT id, project_id, format, was_successful, created_at FROM project_export_metric');
        $this->addSql('DROP TABLE project_export_metric');
        $this->addSql('CREATE TABLE project_export_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, format VARCHAR(20) NOT NULL, was_successful BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_31B6C727166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project_export_metric (id, project_id, format, was_successful, created_at) SELECT id, project_id, format, was_successful, created_at FROM __temp__project_export_metric');
        $this->addSql('DROP TABLE __temp__project_export_metric');
        $this->addSql('CREATE INDEX IDX_217720EA166D1F9C ON project_export_metric (project_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__project_generation_metric AS SELECT id, project_id, provider, model, estimated_cost_usd, created_at FROM project_generation_metric');
        $this->addSql('DROP TABLE project_generation_metric');
        $this->addSql('CREATE TABLE project_generation_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, estimated_cost_usd NUMERIC(10, 4) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_5D6630A8166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project_generation_metric (id, project_id, provider, model, estimated_cost_usd, created_at) SELECT id, project_id, provider, model, estimated_cost_usd, created_at FROM __temp__project_generation_metric');
        $this->addSql('DROP TABLE __temp__project_generation_metric');
        $this->addSql('CREATE INDEX IDX_E5046705166D1F9C ON project_generation_metric (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__project_generation_metric AS SELECT id, project_id, provider, model, estimated_cost_usd, created_at FROM project_generation_metric');
        $this->addSql('DROP TABLE project_generation_metric');
        $this->addSql('CREATE TABLE project_generation_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, estimated_cost_usd NUMERIC(10, 4) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_5D6630A8166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project_generation_metric (id, project_id, provider, model, estimated_cost_usd, created_at) SELECT id, project_id, provider, model, estimated_cost_usd, created_at FROM __temp__project_generation_metric');
        $this->addSql('DROP TABLE __temp__project_generation_metric');
        $this->addSql('CREATE INDEX IDX_5D6630A8166D1F9C ON project_generation_metric (project_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__project_export_metric AS SELECT id, project_id, format, was_successful, created_at FROM project_export_metric');
        $this->addSql('DROP TABLE project_export_metric');
        $this->addSql('CREATE TABLE project_export_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, format VARCHAR(20) NOT NULL, was_successful BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_31B6C727166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project_export_metric (id, project_id, format, was_successful, created_at) SELECT id, project_id, format, was_successful, created_at FROM __temp__project_export_metric');
        $this->addSql('DROP TABLE __temp__project_export_metric');
        $this->addSql('CREATE INDEX IDX_31B6C727166D1F9C ON project_export_metric (project_id)');
    }
}
