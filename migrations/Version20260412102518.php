<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412102518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project generation and export metrics for feature 9 dashboard';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_generation_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, estimated_cost_usd NUMERIC(10, 4) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_5D6630A8166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5D6630A8166D1F9C ON project_generation_metric (project_id)');
        $this->addSql('CREATE TABLE project_export_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, format VARCHAR(20) NOT NULL, was_successful BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_31B6C727166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_31B6C727166D1F9C ON project_export_metric (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_export_metric');
        $this->addSql('DROP TABLE project_generation_metric');
    }
}
