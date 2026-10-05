<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-076: a project's four links — local, dev and production site, and its documentation. Columns this app
 * adds; work-platform's `project` table has no equivalent, so a data copy simply leaves them NULL.
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project local/dev/prod/doc URLs (ADR-076)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD local_url VARCHAR(255) DEFAULT NULL, ADD dev_url VARCHAR(255) DEFAULT NULL, ADD prod_url VARCHAR(255) DEFAULT NULL, ADD doc_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP local_url, DROP dev_url, DROP prod_url, DROP doc_url');
    }
}
