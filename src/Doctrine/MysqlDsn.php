<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Parses a Doctrine-style `mysql://user:pass@host:port/dbname?charset=utf8mb4` DATABASE_URL into what a
 * raw PDO needs. The session handler and the DB console gateway open their own PDO, outside Doctrine, so
 * they cannot ask Doctrine for the connection — they read the SAME DATABASE_URL through this one parser,
 * which is what stops them ever disagreeing with Doctrine about which database they use.
 */
final class MysqlDsn
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $dbname,
        public readonly string $user,
        public readonly string $password,
        public readonly ?string $unixSocket,
    ) {
    }

    /** Returns null for anything that is not a usable mysql:// URL (the caller fails closed). */
    public static function fromUrl(string $databaseUrl): ?self
    {
        if (!preg_match('#^(?:pdo-)?mysql://#i', $databaseUrl)) {
            return null;
        }

        $parts = parse_url($databaseUrl);
        if (!\is_array($parts) || !isset($parts['path'])) {
            return null;
        }

        $dbname = rawurldecode(ltrim($parts['path'], '/'));
        if ($dbname === '') {
            return null;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $socket = isset($query['unix_socket']) && \is_string($query['unix_socket']) && $query['unix_socket'] !== ''
            ? $query['unix_socket']
            : null;

        return new self(
            host: $parts['host'] ?? '127.0.0.1',
            port: (int) ($parts['port'] ?? 3306),
            dbname: $dbname,
            user: rawurldecode($parts['user'] ?? 'root'),
            password: rawurldecode($parts['pass'] ?? ''),
            unixSocket: $socket,
        );
    }

    public function pdoDsn(): string
    {
        if ($this->unixSocket !== null) {
            return sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $this->unixSocket, $this->dbname);
        }

        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->dbname);
    }
}
