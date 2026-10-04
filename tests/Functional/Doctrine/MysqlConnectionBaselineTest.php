<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use App\Doctrine\MysqlConnectionBaseline;
use App\Doctrine\MysqlPdoFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The app's MySQL connection baseline (ADR-066), on a LIVE connection: the values a real Doctrine connection
 * reports — not just that the middleware exists — since a wiring mistake (e.g. DoctrineBundle no longer
 * autoconfiguring Driver\Middleware implementations) would otherwise silently leave every connection on the
 * server's own defaults. Also proves what the baseline is FOR: strict mode and enforced foreign keys.
 */
final class MysqlConnectionBaselineTest extends KernelTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testDoctrineConnectionsRunTheBaseline(): void
    {
        self::assertSame(MysqlConnectionBaseline::SQL_MODE, $this->conn->fetchOne('SELECT @@SESSION.sql_mode'));
        self::assertSame(MysqlConnectionBaseline::LOCK_WAIT_TIMEOUT, (int) $this->conn->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout'));
        self::assertSame('+00:00', $this->conn->fetchOne('SELECT @@SESSION.time_zone'));
        self::assertSame('utf8mb4', $this->conn->fetchOne('SELECT @@SESSION.character_set_connection'));
    }

    public function testFactoryConnectionsRunTheSameBaselineOnTheSameDatabase(): void
    {
        $pdo = (new MysqlPdoFactory())->create((string) self::getContainer()->getParameter('app.session.dsn'));
        $probe = 'SELECT @@SESSION.sql_mode, @@SESSION.innodb_lock_wait_timeout, @@SESSION.time_zone, DATABASE()';

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE), 'PdoSessionHandler requires a throwing PDO');
        self::assertSame(
            array_map('strval', $this->conn->fetchNumeric($probe)),
            array_map('strval', $pdo->query($probe)->fetch(\PDO::FETCH_NUM)),
        );
    }

    public function testAnOverLongValueIsRejectedNotSilentlyTruncated(): void
    {
        $this->expectException(DriverException::class);

        // login_attempts.ip is VARCHAR(45).
        $this->conn->executeStatement(
            'INSERT INTO login_attempts (ip, attempted_at, realm) VALUES (?, ?, ?)',
            [str_repeat('9', 46), '2026-01-01 00:00:00', 'user'],
        );
    }

    public function testForeignKeyCascadesActuallyFire(): void
    {
        // account_lockouts declares `user_id ... ON DELETE CASCADE` against `user`.
        $this->conn->executeStatement(
            'INSERT INTO "user" (email, password, name, roles, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['baseline-fk-test@example.com', 'x', 'x', '[]', 'active', '2026-01-01 00:00:00'],
        );
        $userId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement('INSERT INTO account_lockouts (locked_until, user_id) VALUES (?, ?)', ['2099-01-01 00:00:00', $userId]);

        $this->conn->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM account_lockouts WHERE user_id = ?', [$userId]));
    }

    public function testTheFactoryRefusesANonMysqlUrl(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MySQL-only');

        (new MysqlPdoFactory())->create('sqlite:///var/data.db');
    }
}
