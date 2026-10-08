<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-099: a project's optional Mockup URL, and a task's optional Doc / Specs link (the project links of ADR-076).
 * Work-platform's tables have no equivalent, so a data copy simply leaves them NULL.
 */
final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project mockup URL and task doc / specs URL (ADR-099)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD mockup_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD doc_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP doc_url');
        $this->addSql('ALTER TABLE project DROP mockup_url');
    }
}
