<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-085: house rent — `rent_property` (houses/flats), `rent_tenant` (tenancies), `rent_bill` (a month's rent,
 * electricity and other charges per tenancy) and `rent_payment` (money received, by kind). Created empty.
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'House rent: properties, tenants, monthly bills, payments (ADR-085)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rent_property (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, address LONGTEXT DEFAULT NULL, electricity_rate NUMERIC(8, 2) NOT NULL, default_rent NUMERIC(12, 2) NOT NULL, is_active TINYINT NOT NULL, notes LONGTEXT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rent_tenant (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, phone VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, monthly_rent NUMERIC(12, 2) NOT NULL, deposit NUMERIC(12, 2) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, opening_meter NUMERIC(12, 2) NOT NULL, is_active TINYINT NOT NULL, notes LONGTEXT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, property_id INT NOT NULL, INDEX IDX_6CBAAC29549213EC (property_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rent_bill (id INT AUTO_INCREMENT NOT NULL, period DATE DEFAULT NULL, rent_amount NUMERIC(12, 2) NOT NULL, meter_previous NUMERIC(12, 2) NOT NULL, meter_current NUMERIC(12, 2) NOT NULL, units NUMERIC(12, 2) NOT NULL, rate NUMERIC(8, 2) NOT NULL, electricity_amount NUMERIC(12, 2) NOT NULL, other_amount NUMERIC(12, 2) NOT NULL, other_note VARCHAR(255) DEFAULT NULL, total NUMERIC(12, 2) NOT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, tenant_id INT NOT NULL, INDEX IDX_A4BB7A89033212A (tenant_id), UNIQUE INDEX uniq_rent_bill_tenant_period (tenant_id, period), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rent_payment (id INT AUTO_INCREMENT NOT NULL, paid_on DATE DEFAULT NULL, amount NUMERIC(12, 2) NOT NULL, kind VARCHAR(20) NOT NULL, method VARCHAR(20) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, tenant_id INT NOT NULL, INDEX IDX_8C04FF7D9033212A (tenant_id), INDEX idx_rent_payment_tenant_paid (tenant_id, paid_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE rent_bill ADD CONSTRAINT FK_A4BB7A89033212A FOREIGN KEY (tenant_id) REFERENCES rent_tenant (id)');
        $this->addSql('ALTER TABLE rent_payment ADD CONSTRAINT FK_8C04FF7D9033212A FOREIGN KEY (tenant_id) REFERENCES rent_tenant (id)');
        $this->addSql('ALTER TABLE rent_tenant ADD CONSTRAINT FK_6CBAAC29549213EC FOREIGN KEY (property_id) REFERENCES rent_property (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rent_payment');
        $this->addSql('DROP TABLE rent_bill');
        $this->addSql('DROP TABLE rent_tenant');
        $this->addSql('DROP TABLE rent_property');
    }
}
