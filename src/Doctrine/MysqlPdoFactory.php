<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Builds a raw PDO for code that cannot go through Doctrine — the session handler and the DB console
 * gateway — from the same DATABASE_URL Doctrine uses, with the same baseline ({@see MysqlConnectionBaseline}).
 * Never write `new PDO('mysql:…')` directly: that connection would run without the baseline.
 */
final class MysqlPdoFactory
{
    /** @param string $databaseUrl a resolved URL such as `mysql://user:pass@127.0.0.1:3306/work_manager` */
    public function create(string $databaseUrl): \PDO
    {
        $dsn = MysqlDsn::fromUrl($databaseUrl);
        if ($dsn === null) {
            throw new \LogicException('This application is MySQL-only (ADR-066). The connection needs a "mysql://user:pass@host:port/dbname" DATABASE_URL.');
        }

        // PdoSessionHandler refuses a PDO whose error mode is not "throw".
        $pdo = new \PDO($dsn->pdoDsn(), $dsn->user, $dsn->password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        MysqlConnectionBaseline::applyTo($pdo);

        return $pdo;
    }
}
