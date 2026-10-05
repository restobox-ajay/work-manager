<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-077: invoices — `billing_profile` (who an invoice is from, with its numbering), `invoice` (header with the
 * From/To blocks copied in), `invoice_item` (lines; task_id set on lines made from approved tasks) and
 * `invoice_log` (each invoice's own history). Created empty.
 */
final class Version20261008140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoices: billing_profile, invoice, invoice_item, invoice_log (ADR-077)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_profile (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, street_address1 VARCHAR(255) DEFAULT NULL, street_address2 VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, state VARCHAR(100) DEFAULT NULL, zip_code VARCHAR(20) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, tax_number VARCHAR(50) DEFAULT NULL, invoice_prefix VARCHAR(20) NOT NULL, next_number INT DEFAULT 1 NOT NULL, is_active TINYINT NOT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(50) NOT NULL, kind VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, billing_profile_id INT DEFAULT NULL, client_id INT DEFAULT NULL, invoice_date DATE DEFAULT NULL, due_date DATE DEFAULT NULL, currency VARCHAR(10) NOT NULL, from_name VARCHAR(255) NOT NULL, from_address LONGTEXT DEFAULT NULL, from_email VARCHAR(255) DEFAULT NULL, from_phone VARCHAR(30) DEFAULT NULL, from_tax_number VARCHAR(50) DEFAULT NULL, to_name VARCHAR(255) NOT NULL, to_address LONGTEXT DEFAULT NULL, to_email VARCHAR(255) DEFAULT NULL, to_phone VARCHAR(30) DEFAULT NULL, description LONGTEXT DEFAULT NULL, subtotal NUMERIC(12, 2) NOT NULL, cgst_total NUMERIC(12, 2) NOT NULL, sgst_total NUMERIC(12, 2) NOT NULL, total NUMERIC(12, 2) NOT NULL, emailed_at INT DEFAULT NULL, cancelled_at INT DEFAULT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_by INT DEFAULT NULL, updated_at INT DEFAULT NULL, UNIQUE INDEX UNIQ_9065174496901F54 (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_item (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, task_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, gst_rate NUMERIC(5, 2) NOT NULL, quantity NUMERIC(10, 2) NOT NULL, rate NUMERIC(12, 2) NOT NULL, amount NUMERIC(12, 2) NOT NULL, cgst NUMERIC(12, 2) NOT NULL, sgst NUMERIC(12, 2) NOT NULL, total NUMERIC(12, 2) NOT NULL, invoice_id INT NOT NULL, INDEX IDX_1DDE477B2989F1FD (invoice_id), INDEX idx_invoice_item_task (task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_log (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, action VARCHAR(30) NOT NULL, detail LONGTEXT DEFAULT NULL, user_id INT DEFAULT NULL, created_at INT NOT NULL, INDEX idx_invoice_log_invoice (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE invoice_item ADD CONSTRAINT FK_1DDE477B2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invoice_log');
        $this->addSql('DROP TABLE invoice_item');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('DROP TABLE billing_profile');
    }
}
