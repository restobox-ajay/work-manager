<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-141: a tenant can have several mobile numbers — `rent_tenant.phones` (JSON list of {number, label}) replaces
 * the single `phone` column; each existing phone becomes the first number, with no label.
 */
final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tenants: several mobile numbers (phones JSON) instead of one phone (ADR-141)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rent_tenant ADD phones JSON DEFAULT NULL');
        $this->addSql("UPDATE rent_tenant SET phones = JSON_ARRAY(JSON_OBJECT('number', TRIM(phone), 'label', NULL)) WHERE phone IS NOT NULL AND TRIM(phone) <> ''");
        $this->addSql('ALTER TABLE rent_tenant DROP phone');
    }

    public function down(Schema $schema): void
    {
        // Only the first number fits the old column; any others are lost.
        $this->addSql('ALTER TABLE rent_tenant ADD phone VARCHAR(30) DEFAULT NULL');
        $this->addSql("UPDATE rent_tenant SET phone = LEFT(JSON_UNQUOTE(JSON_EXTRACT(phones, '$[0].number')), 30) WHERE JSON_LENGTH(phones) > 0");
        $this->addSql('ALTER TABLE rent_tenant DROP phones');
    }
}
