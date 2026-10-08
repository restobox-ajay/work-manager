<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-104: a subscription's sign-up details — the name, phone, sign-in method, username, recovery email, billing
 * company, tax id, billing address and card last 4 digits used to open the account.
 */
final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscription sign-up details (ADR-104)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD signup_name VARCHAR(120) DEFAULT NULL, ADD signup_phone VARCHAR(40) DEFAULT NULL, ADD signup_method VARCHAR(20) DEFAULT NULL, ADD account_username VARCHAR(120) DEFAULT NULL, ADD recovery_email VARCHAR(180) DEFAULT NULL, ADD billing_company VARCHAR(160) DEFAULT NULL, ADD tax_id VARCHAR(60) DEFAULT NULL, ADD billing_address LONGTEXT DEFAULT NULL, ADD card_last4 VARCHAR(4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP signup_name, DROP signup_phone, DROP signup_method, DROP account_username, DROP recovery_email, DROP billing_company, DROP tax_id, DROP billing_address, DROP card_last4');
    }
}
