<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-093: the Logs menu's Email Log (`email_log`) and Error Log (`error_log`). The Activity Log reuses `audit_log`.
 */
final class Version20261011140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email log and error log tables (ADR-093)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_log (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(10) NOT NULL, sender VARCHAR(255) DEFAULT NULL, recipients LONGTEXT NOT NULL, cc LONGTEXT DEFAULT NULL, bcc LONGTEXT DEFAULT NULL, subject VARCHAR(255) NOT NULL, attachments LONGTEXT DEFAULT NULL, message_id VARCHAR(255) DEFAULT NULL, error LONGTEXT DEFAULT NULL, created_at INT NOT NULL, updated_at INT NOT NULL, INDEX idx_email_log_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE error_log (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(10) NOT NULL, level VARCHAR(10) NOT NULL, status_code INT DEFAULT NULL, message LONGTEXT NOT NULL, exception_class VARCHAR(255) DEFAULT NULL, file VARCHAR(500) DEFAULT NULL, line INT DEFAULT NULL, trace LONGTEXT DEFAULT NULL, method VARCHAR(10) DEFAULT NULL, path VARCHAR(500) DEFAULT NULL, referrer VARCHAR(500) DEFAULT NULL, user_email VARCHAR(180) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at INT NOT NULL, INDEX idx_error_log_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE error_log');
        $this->addSql('DROP TABLE email_log');
    }
}
