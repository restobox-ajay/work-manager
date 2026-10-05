<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-079: an invoice line's Quantity and Rate may be left empty (the line then bills the Amount typed by hand).
 */
final class Version20261008160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice item quantity and rate become optional (ADR-079)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_item CHANGE quantity quantity NUMERIC(10, 2) DEFAULT NULL, CHANGE rate rate NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE invoice_item SET quantity = COALESCE(quantity, 1.00), rate = COALESCE(rate, amount)');
        $this->addSql('ALTER TABLE invoice_item CHANGE quantity quantity NUMERIC(10, 2) NOT NULL, CHANGE rate rate NUMERIC(12, 2) NOT NULL');
    }
}
