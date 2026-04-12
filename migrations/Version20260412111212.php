<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412111212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store project generation cost in cents for stable SQLite schema comparisons';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__project_generation_metric AS SELECT id, project_id, provider, model, CAST(ROUND(estimated_cost_usd * 100, 0) AS INTEGER) AS estimated_cost_cents, created_at FROM project_generation_metric');
        $this->addSql('DROP TABLE project_generation_metric');
        $this->addSql('CREATE TABLE project_generation_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, estimated_cost_cents INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_5D6630A8166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project_generation_metric (id, project_id, provider, model, estimated_cost_cents, created_at) SELECT id, project_id, provider, model, estimated_cost_cents, created_at FROM __temp__project_generation_metric');
        $this->addSql('DROP TABLE __temp__project_generation_metric');
        $this->addSql('CREATE INDEX IDX_E5046705166D1F9C ON project_generation_metric (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__project_generation_metric AS SELECT id, project_id, provider, model, estimated_cost_cents, created_at FROM project_generation_metric');
        $this->addSql('DROP TABLE project_generation_metric');
        $this->addSql('CREATE TABLE project_generation_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, estimated_cost_usd NUMERIC(10, 4) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_5D6630A8166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO project_generation_metric (id, project_id, provider, model, estimated_cost_usd, created_at) SELECT id, project_id, provider, model, estimated_cost_cents / 100.0, created_at FROM __temp__project_generation_metric');
        $this->addSql('DROP TABLE __temp__project_generation_metric');
        $this->addSql('CREATE INDEX IDX_E5046705166D1F9C ON project_generation_metric (project_id)');
    }
}
