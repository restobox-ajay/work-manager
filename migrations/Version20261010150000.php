<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-090: an expense's category is optional (e.g. upkeep of a rent property); totals show it as "Uncategorised".
 */
final class Version20261010150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expense category optional (ADR-090)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense MODIFY category_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Not reversible while uncategorised expenses exist: give them a category first.
        $this->addSql('ALTER TABLE expense MODIFY category_id INT NOT NULL');
    }
}
