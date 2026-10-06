<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-089: monthly expenses — `expense_category` (seeded with common categories, edited under Config) and `expense`
 * (date, category, amount, method, description, optional rent property).
 */
final class Version20261010140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expenses and expense categories (ADR-089)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, is_active TINYINT NOT NULL, UNIQUE INDEX UNIQ_C02DDB385E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE expense (id INT AUTO_INCREMENT NOT NULL, spent_on DATE DEFAULT NULL, amount NUMERIC(12, 2) NOT NULL, method VARCHAR(20) NOT NULL, description VARCHAR(255) NOT NULL, note LONGTEXT DEFAULT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, category_id INT NOT NULL, property_id INT DEFAULT NULL, INDEX IDX_2D3A8DA612469DE2 (category_id), INDEX IDX_2D3A8DA6549213EC (property_id), INDEX idx_expense_spent_on (spent_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA612469DE2 FOREIGN KEY (category_id) REFERENCES expense_category (id)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6549213EC FOREIGN KEY (property_id) REFERENCES rent_property (id) ON DELETE SET NULL');
        $this->addSql("INSERT INTO expense_category (name, sort_order, is_active) VALUES ('Maintenance', 0, 1), ('Repairs', 1, 1), ('Electricity', 2, 1), ('Water', 3, 1), ('Property tax', 4, 1), ('Salaries', 5, 1), ('Internet & phone', 6, 1), ('Office supplies', 7, 1), ('Travel', 8, 1), ('Other', 9, 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE expense');
        $this->addSql('DROP TABLE expense_category');
    }
}
