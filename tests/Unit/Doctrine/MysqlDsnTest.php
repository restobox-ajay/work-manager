<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\MysqlDsn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MysqlDsn is the one DATABASE_URL parser for the connections opened outside Doctrine (session handler,
 * db-console gateway), so it must read every part of a Doctrine-style URL exactly as Doctrine does.
 */
final class MysqlDsnTest extends TestCase
{
    public function testItReadsEveryPartOfADoctrineStyleUrl(): void
    {
        $dsn = MysqlDsn::fromUrl('mysql://app:s3cret@db.internal:3307/work_manager?serverVersion=8.0.32&charset=utf8mb4');

        self::assertNotNull($dsn);
        self::assertSame(['db.internal', 3307, 'work_manager', 'app', 's3cret', null], [$dsn->host, $dsn->port, $dsn->dbname, $dsn->user, $dsn->password, $dsn->unixSocket]);
        self::assertSame('mysql:host=db.internal;port=3307;dbname=work_manager;charset=utf8mb4', $dsn->pdoDsn());
    }

    public function testAnEmptyPasswordAndTheDefaultPortAreAllowed(): void
    {
        // The WAMP default: root with no password, port omitted.
        $dsn = MysqlDsn::fromUrl('mysql://root:@127.0.0.1/work_manager');

        self::assertNotNull($dsn);
        self::assertSame(['root', '', 3306], [$dsn->user, $dsn->password, $dsn->port]);
    }

    public function testPercentEncodedCredentialsAreDecoded(): void
    {
        $dsn = MysqlDsn::fromUrl('mysql://app%40corp:p%40ss%3Aword@127.0.0.1:3306/app');

        self::assertNotNull($dsn);
        self::assertSame(['app@corp', 'p@ss:word'], [$dsn->user, $dsn->password]);
    }

    public function testAUnixSocketFromTheQueryStringWinsOverHostAndPort(): void
    {
        $dsn = MysqlDsn::fromUrl('mysql://app:pw@localhost/app?unix_socket=/var/run/mysqld/mysqld.sock');

        self::assertNotNull($dsn);
        self::assertSame('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=app;charset=utf8mb4', $dsn->pdoDsn());
    }

    /** @return array<string, array{string}> */
    public static function notAUsableMysqlUrl(): array
    {
        return [
            'sqlite' => ['sqlite:///%kernel.project_dir%/var/data.db'],
            'postgres' => ['postgresql://app:pw@127.0.0.1:5432/app'],
            'no database name' => ['mysql://app:pw@127.0.0.1:3306/'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notAUsableMysqlUrl')]
    public function testAnythingButAUsableMysqlUrlIsRejected(string $url): void
    {
        self::assertNull(MysqlDsn::fromUrl($url));
    }
}
