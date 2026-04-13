<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * HRM-F40 — Extend project_generation_metric with slide_count, duration_ms,
 * iteration_count, error_count and accepted_slide_count columns (T323).
 *
 * Also renames estimated_cost_usd (NUMERIC) → estimated_cost_cents (INTEGER)
 * so the persisted value matches the entity field added in a prior refactor.
 * Existing rows are converted (USD × 100 → cents, rounded).
 */
final class Version20260412204640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HRM-F40 T323 — Add slide_count, duration_ms, iteration_count, error_count, accepted_slide_count to project_generation_metric; rename estimated_cost_usd to estimated_cost_cents';
    }

    public function up(Schema $schema): void
    {
        // SQLite does not support ALTER TABLE RENAME COLUMN before 3.25, so we
        // recreate the table and migrate data in one step.
        $this->addSql('CREATE TEMPORARY TABLE __temp__pgm AS SELECT id, project_id, provider, model, estimated_cost_usd, created_at FROM project_generation_metric');
        $this->addSql('DROP TABLE project_generation_metric');
        $this->addSql('CREATE TABLE project_generation_metric (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            project_id INTEGER NOT NULL,
            provider VARCHAR(40) NOT NULL,
            model VARCHAR(80) NOT NULL,
            estimated_cost_cents INTEGER DEFAULT 0 NOT NULL,
            slide_count INTEGER DEFAULT 0 NOT NULL,
            duration_ms INTEGER DEFAULT NULL,
            iteration_count INTEGER DEFAULT 1 NOT NULL,
            error_count INTEGER DEFAULT 0 NOT NULL,
            accepted_slide_count INTEGER DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL,
            CONSTRAINT FK_E5046705166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        // Migrate existing rows: convert USD float to cents integer
        $this->addSql('INSERT INTO project_generation_metric (id, project_id, provider, model, estimated_cost_cents, slide_count, duration_ms, iteration_count, error_count, accepted_slide_count, created_at)
            SELECT id, project_id, provider, model, CAST(ROUND(CAST(estimated_cost_usd AS REAL) * 100) AS INTEGER), 0, NULL, 1, 0, 0, created_at
            FROM __temp__pgm');
        $this->addSql('DROP TABLE __temp__pgm');
        $this->addSql('CREATE INDEX IDX_E5046705166D1F9C ON project_generation_metric (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__pgm AS SELECT id, project_id, provider, model, estimated_cost_cents, created_at FROM project_generation_metric');
        $this->addSql('DROP TABLE project_generation_metric');
        $this->addSql('CREATE TABLE project_generation_metric (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            project_id INTEGER NOT NULL,
            provider VARCHAR(40) NOT NULL,
            model VARCHAR(80) NOT NULL,
            estimated_cost_usd NUMERIC(10, 4) DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL,
            CONSTRAINT FK_E5046705166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO project_generation_metric (id, project_id, provider, model, estimated_cost_usd, created_at)
            SELECT id, project_id, provider, model, CAST(estimated_cost_cents AS REAL) / 100.0, created_at
            FROM __temp__pgm');
        $this->addSql('DROP TABLE __temp__pgm');
        $this->addSql('CREATE INDEX IDX_E5046705166D1F9C ON project_generation_metric (project_id)');
    }
}
