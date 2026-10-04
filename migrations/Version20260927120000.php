<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Squashed core schema (no production deploys predate this point, so the 38 incremental
 * `DoctrineMigrations\...` migrations that built and reshaped this schema over time — including the
 * intermediate table-rebuild dances and the columns later relocated into bundle-owned satellite
 * tables — collapse into this single migration; only the FINAL shape matters going forward).
 *
 * Excludes every table now owned outright by an optional bundle's own migrations dir (migration-ownership
 * pattern, ADR-010/040/043/044/045/046): `personal_access_tokens` (auth-pat-bundle), `magic_link_tokens`
 * (auth-magic-link-bundle), `login_attempts` / `endpoint_rate_limits` (auth-security-bundle),
 * `password_history` (auth-password-policy-bundle), and the satellite tables `two_factor_settings`
 * (auth-2fa-bundle), `account_lockouts` (auth-security-bundle), `password_meta`
 * (auth-password-policy-bundle), `user_ip_whitelist` (auth-ip-whitelist-bundle) — `user` / `admin` are
 * created here in their FINAL shape, without the columns those four satellites now hold, since there is no
 * pre-extraction history left to relocate data out of. `webhook_delivery` stays here on purpose (ADR-042):
 * auth-webhook-bundle's AC3 requires the table to persist even when that bundle is uninstalled, so core
 * keeps owning its creation and the bundle's own migration stays a guarded no-op.
 *
 * MySQL 8 (ADR-066): InnoDB, utf8mb4 / utf8mb4_unicode_ci — the same options doctrine.yaml's
 * default_table_options give the ORM, so schema:validate compares like with like.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squashed core schema (pre-production; supersedes the 38 incremental app migrations)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `admin` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, totp_secret VARCHAR(64) DEFAULT NULL, is_totp_enabled TINYINT DEFAULT 0 NOT NULL, last_totp_counter INT DEFAULT NULL, UNIQUE INDEX UNIQ_880E0D76E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE admin_access_tokens (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, name VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_AAT_ADMIN_ID (admin_id), UNIQUE INDEX UNIQ_86135BDBB3BC57DA (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE admin_login_history (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_admin_login_history_admin_id (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE admin_login_notification_seen (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, marker VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_admin_login_notification_seen_admin_id (admin_id), UNIQUE INDEX UNIQ_admin_login_notification_seen_admin_marker (admin_id, marker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE admin_password_reset_tokens (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_APRT_EMAIL (email), UNIQUE INDEX UNIQ_D427069FB3BC57DA (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE admin_sessions (id INT AUTO_INCREMENT NOT NULL, session_id VARCHAR(128) NOT NULL, admin_id INT NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, created_at DATETIME NOT NULL, last_active_at DATETIME NOT NULL, INDEX IDX_admin_sessions_admin_id (admin_id), UNIQUE INDEX UNIQ_admin_sessions_session_id (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, actor VARCHAR(255) NOT NULL, actor_type VARCHAR(10) NOT NULL, ip VARCHAR(45) NOT NULL, action VARCHAR(100) NOT NULL, outcome VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, context LONGTEXT DEFAULT NULL, INDEX IDX_AUDIT_LOG_CREATED_AT (created_at), INDEX IDX_AUDIT_LOG_ACTION (action), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE config (id INT AUTO_INCREMENT NOT NULL, config_key VARCHAR(255) NOT NULL, config_value LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_D48A2F7C95D1CAA6 (config_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE db_console_session (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, admin_id INT NOT NULL, expires_at DATETIME NOT NULL, ip_address VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_db_console_session_token (token_hash), INDEX idx_db_console_session_admin (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE db_console_throttle (ip_address VARCHAR(45) NOT NULL, window_start INT NOT NULL, attempts INT NOT NULL, PRIMARY KEY (ip_address)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE invitations (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_232710AEB3BC57DA (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE login_history (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_login_history_user_id (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE login_notification_seen (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, marker VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_login_notification_seen_user_id (user_id), UNIQUE INDEX UNIQ_login_notification_seen_user_marker (user_id, marker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Symfony Messenger's Doctrine transport table (DBAL-only, outside the ORM — see doctrine.yaml schema_filter).
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_MSG_QUEUE_NAME (queue_name), INDEX IDX_MSG_AVAILABLE_AT (available_at), INDEX IDX_MSG_DELIVERED_AT (delivered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE password_reset_tokens (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_3967A216B3BC57DA (token_hash), INDEX IDX_PRT_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // PdoSessionHandler's table, in Symfony's own MySQL shape (VARBINARY id, BLOB data, unsigned ints).
        $this->addSql('CREATE TABLE sessions (sess_id VARBINARY(128) NOT NULL, sess_data BLOB NOT NULL, sess_lifetime INT UNSIGNED NOT NULL, sess_time INT UNSIGNED NOT NULL, INDEX sess_lifetime_idx (sess_lifetime), PRIMARY KEY (sess_id)) COLLATE `utf8mb4_bin` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, roles JSON NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, login_notifications_enabled TINYINT DEFAULT 1 NOT NULL, is_verified TINYINT DEFAULT 0 NOT NULL, sessions_invalidated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE user_sessions (id INT AUTO_INCREMENT NOT NULL, session_id VARCHAR(128) NOT NULL, user_id INT NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, created_at DATETIME NOT NULL, last_active_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_user_sessions_session_id (session_id), INDEX IDX_user_sessions_user_id (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create-if-not-exists: auth-webhook-bundle's own migration
        // (App\Bundle\AuthWebhook\Migrations\Version20260707140000) sorts BEFORE this one across
        // registered migration paths ('A' < 'D' by fully-qualified class name), so it may already have
        // created the table when the bundle is enabled. Core still owns creating it (ADR-042: the
        // bundle's AC3 requires webhook_delivery to persist even when the bundle is uninstalled).
        // The guard is declarative (IF NOT EXISTS), not an introspection of the live database: in a --dry-run /
        // --write-sql nothing executes, so both migrations would see no table and both emit a plain CREATE, and
        // the written SQL would not replay (issue #38). MySQL has no CREATE INDEX IF NOT EXISTS, so the indexes
        // are declared inline — they come and go with the table itself.
        $this->addSql('CREATE TABLE IF NOT EXISTS webhook_delivery (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, event_type VARCHAR(100) NOT NULL, payload LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, response_code INT DEFAULT NULL, attempted_at DATETIME NOT NULL, INDEX idx_webhook_delivery_event_type (event_type), INDEX idx_webhook_delivery_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_delivery');
        $this->addSql('DROP TABLE user_sessions');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE login_notification_seen');
        $this->addSql('DROP TABLE login_history');
        $this->addSql('DROP TABLE invitations');
        $this->addSql('DROP TABLE db_console_throttle');
        $this->addSql('DROP TABLE db_console_session');
        $this->addSql('DROP TABLE config');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE admin_sessions');
        $this->addSql('DROP TABLE admin_password_reset_tokens');
        $this->addSql('DROP TABLE admin_login_notification_seen');
        $this->addSql('DROP TABLE admin_login_history');
        $this->addSql('DROP TABLE admin_access_tokens');
        $this->addSql('DROP TABLE `admin`');
    }
}
