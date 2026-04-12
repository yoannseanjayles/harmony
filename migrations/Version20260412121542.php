<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412121542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add chat_message entity for feature 10 project chat history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_id INTEGER NOT NULL, role VARCHAR(20) NOT NULL, content CLOB NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_5A9B2008166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5A9B2008166D1F9C ON chat_message (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_message');
    }
}
