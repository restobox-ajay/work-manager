<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-097: `expense.payment_details` — an optional reference for how an expense was paid (UPI id / transaction no.,
 * cheque no. and bank, bank transfer reference).
 */
final class Version20261011160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expense payment details (ADR-097)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense ADD payment_details VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense DROP payment_details');
    }
}
