<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-075: the work-platform reference lists Config adds — tags, payer entities, wallet entities, payment
 * methods, email templates and countries. Same columns as work-platform; created empty (work-platform seeds none).
 */
final class Version20261008130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Config reference tables from work-platform: tag, payer, wallet_entity, payment_method, email_template, country (ADR-075)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_template (id INT AUTO_INCREMENT NOT NULL, module VARCHAR(100) NOT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE wallet_entity (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, owner_name VARCHAR(255) DEFAULT NULL, created_at VARCHAR(255) DEFAULT NULL, updated_at VARCHAR(255) DEFAULT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payer (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, company_name VARCHAR(255) NOT NULL, contact_name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, status TINYINT NOT NULL, type VARCHAR(50) NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payment_method (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT NOT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, created_at INT DEFAULT NULL, updated_at INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE country (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(2) NOT NULL, name VARCHAR(100) NOT NULL, sort_order INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, type VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE country');
        $this->addSql('DROP TABLE payment_method');
        $this->addSql('DROP TABLE payer');
        $this->addSql('DROP TABLE wallet_entity');
        $this->addSql('DROP TABLE email_template');
    }
}
