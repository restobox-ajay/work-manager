<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-105: a description for a subscription signed up with "Other".
 */
final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscription sign-up method "Other" description (ADR-105)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD signup_method_note VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP signup_method_note');
    }
}
