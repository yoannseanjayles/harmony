<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T201 / T203 — Add themeOverridesJson and themeVersion columns to the project table.
 *
 * - themeOverridesJson: stores the user's manual token overrides delta separately from the
 *   preset base tokens (themeConfigJson), enabling per-project overrides and reset-to-preset.
 * - themeVersion: monotonically-increasing counter incremented by ThemeEngine on every theme
 *   change so that the slide renderHash is correctly invalidated (T204).
 */
final class Version20260412181252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T201/T203 — Add theme_overrides_json and theme_version to project table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, api_key_encrypted CLOB DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)');
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, role VARCHAR(20) NOT NULL, content CLOB NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_FAB3FC16166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FAB3FC16166D1F9C ON chat_message (project_id)');
        $this->addSql('CREATE TABLE project (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(160) NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, slides_json CLOB DEFAULT \'[]\' NOT NULL, theme_config_json CLOB DEFAULT \'{}\' NOT NULL, theme_overrides_json CLOB DEFAULT \'{}\' NOT NULL, theme_version INTEGER DEFAULT 1 NOT NULL, metadata_json CLOB DEFAULT \'{}\' NOT NULL, media_refs_json CLOB DEFAULT \'[]\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, share_token VARCHAR(255) DEFAULT NULL, share_expires_at DATETIME DEFAULT NULL, is_public BOOLEAN DEFAULT 0 NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_2FB3D0EEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2FB3D0EEA76ED395 ON project (user_id)');
        $this->addSql('CREATE TABLE project_export_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, format VARCHAR(20) NOT NULL, was_successful BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_217720EA166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_217720EA166D1F9C ON project_export_metric (project_id)');
        $this->addSql('CREATE TABLE project_generation_metric (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, provider VARCHAR(40) NOT NULL, model VARCHAR(80) NOT NULL, estimated_cost_cents INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_E5046705166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E5046705166D1F9C ON project_generation_metric (project_id)');
        $this->addSql('CREATE TABLE project_version (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, version_number INTEGER NOT NULL, snapshot_json CLOB DEFAULT \'{}\' NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_2902DFA6166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2902DFA6166D1F9C ON project_version (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_version_number ON project_version (project_id, version_number)');
        $this->addSql('CREATE TABLE security_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event_type VARCHAR(80) NOT NULL, user_id INTEGER DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, occurred_at DATETIME NOT NULL, payload CLOB NOT NULL)');
        $this->addSql('CREATE TABLE slide (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(30) NOT NULL, content_json CLOB DEFAULT \'{}\' NOT NULL, position INTEGER NOT NULL, render_hash VARCHAR(64) DEFAULT NULL, html_cache CLOB DEFAULT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_72EFEE62166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_72EFEE62166D1F9C ON slide (project_id)');
        $this->addSql('CREATE TABLE theme (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(80) NOT NULL, tokens_json CLOB DEFAULT \'{}\' NOT NULL, version INTEGER DEFAULT 1 NOT NULL, is_preset BOOLEAN DEFAULT 0 NOT NULL, user_id INTEGER DEFAULT NULL, CONSTRAINT FK_9775E708A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9775E708A76ED395 ON theme (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_export_metric');
        $this->addSql('DROP TABLE project_generation_metric');
        $this->addSql('DROP TABLE project_version');
        $this->addSql('DROP TABLE security_log');
        $this->addSql('DROP TABLE slide');
        $this->addSql('DROP TABLE theme');
    }
}
