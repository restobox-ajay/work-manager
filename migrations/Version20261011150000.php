<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-094: `vault_key.auth_hash` — the hash of the auth key the browser derives from the master password; changing
 * or deleting vault entries must prove it. Existing vaults register it at their next unlock.
 */
final class Version20261011150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password Manager auth key hash (ADR-094)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault_key ADD auth_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault_key DROP auth_hash');
    }
}
