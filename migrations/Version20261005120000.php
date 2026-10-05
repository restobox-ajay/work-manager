<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-068: one account type. Every admin becomes a row in `user` (same email, password hash, name, roles,
 * status and creation date; 2FA enrolment carried into two_factor_settings), then the six admin tables are
 * dropped. The DB console's owner column follows (admin_id → user_id); open consoles are closed because their
 * ids pointed at admins.
 *
 * An admin whose email is already a user's would have to be merged by hand (which account's password and
 * roles win is a human call), so the migration refuses to run instead of guessing.
 */
final class Version20261005120000 extends AbstractMigration
{
    private const ADMIN_TABLES = [
        'admin_access_tokens',
        'admin_login_history',
        'admin_login_notification_seen',
        'admin_password_reset_tokens',
        'admin_sessions',
        'admin',
    ];

    public function getDescription(): string
    {
        return 'Fold admins into the user table and drop the admin tables (ADR-068)';
    }

    public function up(Schema $schema): void
    {
        // Checked against the live database; in a --dry-run on an empty one there is nothing to compare yet.
        $clashes = $schema->hasTable('admin')
            ? (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `admin` a JOIN `user` u ON u.email = a.email')
            : 0;
        $this->abortIf($clashes > 0, sprintf(
            '%d admin email(s) also belong to a user account. Rename or remove one of each pair, then run the migration again.',
            $clashes,
        ));

        // An admin's stored roles never included the implied ROLE_ADMIN; a plain admin stored [] — make it explicit.
        $this->addSql(<<<'SQL'
            INSERT INTO `user` (email, password, name, roles, status, created_at, login_notifications_enabled, is_verified, sessions_invalidated_at)
            SELECT a.email, a.password, a.name,
                   CASE WHEN JSON_LENGTH(a.roles) = 0 THEN JSON_ARRAY('ROLE_ADMIN') ELSE a.roles END,
                   a.status, a.created_at, 1, 1, NULL
              FROM `admin` a
            SQL);

        if ($schema->hasTable('two_factor_settings')) {
            $this->addSql(<<<'SQL'
                INSERT INTO two_factor_settings (totp_secret, is_totp_enabled, last_totp_counter, user_id)
                SELECT a.totp_secret, a.is_totp_enabled, a.last_totp_counter, u.id
                  FROM `admin` a JOIN `user` u ON u.email = a.email
                 WHERE a.is_totp_enabled = 1
                SQL);
        }

        $this->addSql('DELETE FROM db_console_session');
        $this->addSql('ALTER TABLE db_console_session DROP INDEX idx_db_console_session_admin');
        $this->addSql('ALTER TABLE db_console_session RENAME COLUMN admin_id TO user_id');
        $this->addSql('CREATE INDEX idx_db_console_session_user ON db_console_session (user_id)');

        foreach (self::ADMIN_TABLES as $table) {
            $this->addSql(sprintf('DROP TABLE `%s`', $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Admins were merged into `user`; which users were admins is only recorded in their roles.');
    }
}
