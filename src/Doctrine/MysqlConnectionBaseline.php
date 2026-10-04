<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The one definition of the app's MySQL connection baseline (ADR-066), shared by EVERY way the app
 * connects:
 *   - Doctrine's DBAL connections, via {@see MysqlConnectionMiddleware};
 *   - the session handler's own separate PDO connection, via {@see MysqlPdoFactory}
 *     (PdoSessionHandler does not use Doctrine, so the middleware never reaches it);
 *   - the DB console gateway (public/db-admin.php), via {@see MysqlPdoFactory}.
 *
 * These are per-session settings, so each new connection must run them:
 *   - `NAMES utf8mb4` — full Unicode on the wire, matching the utf8mb4 tables;
 *   - a strict `sql_mode` — MySQL then REJECTS an over-long VARCHAR, a bad date or a missing NOT NULL
 *     value instead of silently truncating or zero-filling it. It includes ANSI_QUOTES, so a double-quoted
 *     name is an identifier, as in standard SQL: raw SQL may write `"user"` (a keyword) or `` `user` ``;
 *     string literals are always single-quoted;
 *   - `innodb_lock_wait_timeout = 5` — wait at most 5 s for a row lock, then fail (the old SQLite
 *     busy_timeout of 5000 ms, carried over);
 *   - `time_zone = '+00:00'` — CURRENT_TIMESTAMP in raw SQL agrees across servers whatever their
 *     own zone is.
 */
final class MysqlConnectionBaseline
{
    public const SQL_MODE = 'ANSI_QUOTES,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    public const LOCK_WAIT_TIMEOUT = 5;

    /** @var list<string> */
    public const STATEMENTS = [
        "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        "SET SESSION sql_mode = '" . self::SQL_MODE . "'",
        'SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_TIMEOUT,
        "SET SESSION time_zone = '+00:00'",
    ];

    public static function applyTo(\PDO $pdo): void
    {
        foreach (self::STATEMENTS as $statement) {
            $pdo->exec($statement);
        }
    }
}
