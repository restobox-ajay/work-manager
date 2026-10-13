<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-128: a project may have no client.
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project client optional (ADR-128)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project CHANGE client_id client_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Fails while any project has no client: give those a client (or remove them) first.
        $this->addSql('ALTER TABLE project CHANGE client_id client_id INT NOT NULL');
    }
}
