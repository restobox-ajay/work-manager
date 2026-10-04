<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #39 / ADR-064: admin API tokens get a `token_hint` (last 6 characters of the plaintext) so the
 * token pages and the CLI can show WHICH token is which before you revoke it. Nullable: tokens issued before
 * this migration have no hint (the plaintext was never stored).
 *
 * Backfill: tokens of admins that are ALREADY inactive (deactivated or soft-deleted before this release) are
 * revoked now. Until this change those were only rejected while the admin stayed inactive, so reactivating the
 * account would have revived them — the exact bug this release fixes for future deactivations.
 *
 * MySQL 8 (ADR-066): a plain in-place `ADD` / `DROP` of the nullable column.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_access_tokens.token_hint (last 6 chars of the plaintext) for token identification (issue #39)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_access_tokens ADD token_hint VARCHAR(6) DEFAULT NULL');
        $this->addSql("UPDATE admin_access_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE revoked_at IS NULL AND admin_id IN (SELECT id FROM `admin` WHERE status = 'inactive')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_access_tokens DROP token_hint');
    }
}
