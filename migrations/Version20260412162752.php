<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T177 — Create the `theme` table for HRM-F22 — Presets de thèmes.
 *
 * Stores built-in preset themes (isPreset = 1, user_id = NULL) and future
 * user-owned custom themes (isPreset = 0, user_id = <user>).
 */
final class Version20260412162752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create theme table for HRM-T177 — Theme entity (name, tokensJson, version, isPreset, userId)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE theme (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER DEFAULT NULL, name VARCHAR(80) NOT NULL, tokens_json CLOB DEFAULT \'{}\' NOT NULL, version INTEGER DEFAULT 1 NOT NULL, is_preset BOOLEAN DEFAULT 0 NOT NULL, CONSTRAINT FK_9775E708A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9775E708A76ED395 ON theme (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE theme');
    }
}
