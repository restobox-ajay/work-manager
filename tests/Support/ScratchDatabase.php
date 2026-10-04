<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Doctrine\MysqlDsn;
use App\Doctrine\MysqlPdoFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * A throwaway MySQL database on the same server (and with the same credentials) as the test DATABASE_URL, for
 * tests that need a database of their own — a pre-migration shape, a fresh migration replay, a gateway world —
 * instead of the shared, fully-migrated test database. The SQLite-era equivalent was a temp file. Always drop()
 * it in tearDown().
 */
final class ScratchDatabase
{
    private function __construct(
        public readonly string $name,
        public readonly string $url,
        private readonly string $serverUrl,
    ) {
    }

    public static function create(string $prefix): self
    {
        $serverUrl = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '');
        $server = MysqlDsn::fromUrl($serverUrl) ?? throw new \LogicException('The test DATABASE_URL must be a mysql:// URL.');

        $name = sprintf('%s_%s_%s', $server->dbname, $prefix, bin2hex(random_bytes(4)));
        if (preg_match('/^\w{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a usable scratch database name.', $name));
        }

        (new MysqlPdoFactory())->create($serverUrl)->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $name));

        $url = preg_replace('#^([^?]*/)[^/?]+#', '${1}' . $name, $serverUrl, 1);

        return new self($name, (string) $url, $serverUrl);
    }

    /** A plain DBAL connection (no app middleware) to the scratch database. */
    public function connection(): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($this->url);

        return DriverManager::getConnection($params);
    }

    /** A PDO with the app's connection baseline, as the app itself would open it. */
    public function pdo(): \PDO
    {
        return (new MysqlPdoFactory())->create($this->url);
    }

    public function drop(): void
    {
        (new MysqlPdoFactory())->create($this->serverUrl)->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->name));
    }
}
