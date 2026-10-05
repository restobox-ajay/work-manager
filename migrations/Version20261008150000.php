<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-078: Settings › Tax Rates. `tax_rate`, seeded with India's GST slabs (0, 5, 12, 18, 28 %) — the rates an
 * invoice line's GST % is chosen from. Edit or switch them off under Settings.
 */
final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tax rates for invoice GST, with the GST slabs seeded (ADR-078)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tax_rate (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(10) NOT NULL, name VARCHAR(60) NOT NULL, rate NUMERIC(5, 2) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, is_active TINYINT NOT NULL, updated_at INT DEFAULT NULL, updated_by INT DEFAULT NULL, UNIQUE INDEX UNIQ_C36330C177153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql("INSERT INTO tax_rate (code, name, rate, sort_order, is_active) VALUES ('GST0', 'GST 0', 0.00, 0, 1), ('GST5', 'GST 5', 5.00, 1, 1), ('GST12', 'GST 12', 12.00, 2, 1), ('GST18', 'GST 18', 18.00, 3, 1), ('GST28', 'GST 28', 28.00, 4, 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tax_rate');
    }
}
