<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-080: clients get a company name (the registered business name), printed as Billed To on invoices when set.
 */
final class Version20261009130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Client company name (ADR-080)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD company_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP company_name');
    }
}
