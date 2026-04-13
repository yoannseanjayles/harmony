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
        $this->addSql('ALTER TABLE project ADD COLUMN theme_overrides_json CLOB DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE project ADD COLUMN theme_version INTEGER DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP COLUMN theme_overrides_json');
        $this->addSql('ALTER TABLE project DROP COLUMN theme_version');
    }
}
