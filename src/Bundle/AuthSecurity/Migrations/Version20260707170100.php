<?php

declare(strict_types=1);

namespace SecurityBundleMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-security-bundle owns the DBAL-only `login_attempts` store (FEATURE-144 / ADR-044,
 * migration-ownership pattern; mirrors auth-pat-bundle's create, ADR-010). `login_attempts` is
 * DBAL-only (no entity) and stays in the core doctrine `schema_filter`, so it never appears in
 * schema:validate / migrations:diff regardless.
 *
 * The sole, unconditional creator (no production deploy predates this point, so the legacy
 * app-owned create this used to guard against — and defer to — no longer exists; see the
 * migrations/ squash, ADR-054). Registered ONLY via AuthSecurityExtension::prepend(), so a deploy
 * without the bundle never runs it, and the table never exists without it.
 */
final class Version20260707170100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-security-bundle owns login_attempts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE login_attempts (id INT AUTO_INCREMENT NOT NULL, ip VARCHAR(45) NOT NULL, attempted_at DATETIME NOT NULL, email VARCHAR(254) DEFAULT NULL, realm VARCHAR(10) DEFAULT \'user\' NOT NULL, INDEX idx_login_attempts_ip_time (ip, attempted_at), INDEX idx_login_attempts_email_time (email, attempted_at), INDEX idx_login_attempts_realm_email_time (realm, email, attempted_at), INDEX idx_login_attempts_realm_ip_time (realm, ip, attempted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_attempts');
    }
}
