<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * HRM-T331 — Add durationMs and failureReason columns to project_export_metric.
 */
final class Version20260413080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HRM-T331 — Add durationMs and failureReason to project_export_metric';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_export_metric ADD COLUMN duration_ms INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE project_export_metric ADD COLUMN failure_reason CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite does not support DROP COLUMN before 3.35 — recreate without the new columns.
        $this->addSql('CREATE TEMPORARY TABLE project_export_metric_backup AS SELECT id, project_id, format, was_successful, created_at FROM project_export_metric');
        $this->addSql('DROP TABLE project_export_metric');
        $this->addSql('CREATE TABLE project_export_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, format VARCHAR(20) NOT NULL, was_successful BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_31B6C727166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project_export_metric SELECT * FROM project_export_metric_backup');
        $this->addSql('DROP TABLE project_export_metric_backup');
        $this->addSql('CREATE INDEX IDX_31B6C727166D1F9C ON project_export_metric (project_id)');
    }
}
