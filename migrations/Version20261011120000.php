<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-091: subscriptions — `subscription_category` (seeded, edited under Config), `subscription` and its renewal
 * payments `subscription_payment`.
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscriptions, categories and payments (ADR-091)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, is_active TINYINT NOT NULL, UNIQUE INDEX UNIQ_61E246ED5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE subscription (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, vendor VARCHAR(120) DEFAULT NULL, plan VARCHAR(120) DEFAULT NULL, amount NUMERIC(12, 2) NOT NULL, currency VARCHAR(3) NOT NULL, billing_cycle VARCHAR(20) NOT NULL, start_date DATE DEFAULT NULL, next_renewal DATE DEFAULT NULL, auto_renew TINYINT DEFAULT 1 NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, account_email VARCHAR(180) DEFAULT NULL, seats INT DEFAULT NULL, assigned_to VARCHAR(120) DEFAULT NULL, payment_method VARCHAR(120) DEFAULT NULL, website_url VARCHAR(255) DEFAULT NULL, billing_url VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, category_id INT DEFAULT NULL, INDEX IDX_A3C664D312469DE2 (category_id), INDEX idx_subscription_next_renewal (next_renewal), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE subscription_payment (id INT AUTO_INCREMENT NOT NULL, paid_on DATE NOT NULL, amount NUMERIC(12, 2) NOT NULL, currency VARCHAR(3) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_by INT DEFAULT NULL, created_at INT DEFAULT NULL, subscription_id INT NOT NULL, INDEX IDX_1E3D64969A1887DC (subscription_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D312469DE2 FOREIGN KEY (category_id) REFERENCES subscription_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE subscription_payment ADD CONSTRAINT FK_1E3D64969A1887DC FOREIGN KEY (subscription_id) REFERENCES subscription (id) ON DELETE CASCADE');
        $this->addSql("INSERT INTO subscription_category (name, sort_order, is_active) VALUES ('AI tools', 0, 1), ('Development tools', 1, 1), ('Office & productivity', 2, 1), ('Operating system', 3, 1), ('Design', 4, 1), ('Hosting & domains', 5, 1), ('Cloud storage', 6, 1), ('Communication', 7, 1), ('Security', 8, 1), ('Entertainment', 9, 1), ('Other', 10, 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subscription_payment');
        $this->addSql('DROP TABLE subscription');
        $this->addSql('DROP TABLE subscription_category');
    }
}
