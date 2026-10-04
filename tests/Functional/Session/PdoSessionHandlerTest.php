<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Tests\Support\TableInfo;
use App\Doctrine\MysqlDsn;
use App\Doctrine\MysqlPdoFactory;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerTest extends KernelTestCase
{
    private const SHIPPED_DEFAULT = '@.env';

    /**
     * Regression tests for a long-standing bug and its first (wrong) fix (ADR-061): the session handler once
     * read/wrote a different database than Doctrine. The only invariant that cannot drift is that the session
     * DSN IS DATABASE_URL, so that is what is asserted — for the default AND a custom URL, in dev AND prod. The
     * real kernels are booted (bootKernel() gives the `test` one, whose own override hid the original bug).
     *
     * @return iterable<string,array{string,string}>
     */
    public static function environmentsAndDatabaseUrls(): iterable
    {
        foreach (['dev', 'prod'] as $environment) {
            yield "$environment, the shipped default from .env" => [$environment, self::SHIPPED_DEFAULT];
            yield "$environment, custom DATABASE_URL on persistent storage" => [$environment, 'mysql://app:s3cret@db.internal:3307/app_data?serverVersion=8.0.32'];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('environmentsAndDatabaseUrls')]
    public function testSessionDsnIsDatabaseUrlSoSessionsAndDataShareOneDatabase(string $environment, string $urlCase): void
    {
        // Inside PHPUnit .env.test is already in effect, so "the shipped default" must be loaded from .env
        // itself and put in force explicitly — otherwise a dev/prod kernel would just see the test database.
        $customUrl = $urlCase === self::SHIPPED_DEFAULT ? null : $urlCase;
        $databaseUrl = $customUrl ?? (new \Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents(\dirname(__DIR__, 3) . '/.env'))['DATABASE_URL'];

        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null, getenv('DATABASE_URL')];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $databaseUrl;
        putenv('DATABASE_URL=' . $databaseUrl);

        $kernel = new Kernel($environment, true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            $sessionDsn = (string) $container->getParameter('app.session.dsn');

            // Which database does each side mean? MysqlDsn is the one DATABASE_URL parser shared by the session
            // handler's connection and the db-console gateway.
            $expected = MysqlDsn::fromUrl($databaseUrl);
            $actual = MysqlDsn::fromUrl($sessionDsn);

            self::assertNotNull($expected, 'DATABASE_URL must be a mysql:// URL (the app is MySQL-only).');
            self::assertEquals($expected, $actual, "In '$environment' the session handler must use the SAME database as DATABASE_URL.");
            if ($customUrl !== null) {
                self::assertSame(['db.internal', 3307, 'app_data'], [$actual->host, $actual->port, $actual->dbname], 'a custom DATABASE_URL must be honoured by the session handler too');
            } else {
                self::assertSame('work_manager', $actual?->dbname);
            }
        } finally {
            $kernel->shutdown();
            $this->restoreDatabaseUrl($previous);
        }
    }

    /** @param array{0:mixed,1:mixed,2:string|false} $previous what $_SERVER / $_ENV / getenv() held before */
    private function restoreDatabaseUrl(array $previous): void
    {
        [$server, $env, $getenv] = $previous;

        if ($server === null) {
            unset($_SERVER['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $server;
        }
        if ($env === null) {
            unset($_ENV['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $env;
        }
        putenv($getenv === false ? 'DATABASE_URL' : 'DATABASE_URL=' . $getenv);
    }

    public function testSessionsWrittenThroughTheFactoryConnectionLandInTheDoctrineDatabase(): void
    {
        // Production hands the handler a PDO built by MysqlPdoFactory from DATABASE_URL. Prove a write through
        // such a handler is visible to Doctrine — not merely that the parameter looks right.
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $doctrine */
        $doctrine = self::getContainer()->get('doctrine.dbal.default_connection');
        $sessionId = 'url_form_' . bin2hex(random_bytes(8));

        $handler = new PdoSessionHandler(
            (new MysqlPdoFactory())->create((string) self::getContainer()->getParameter('app.session.dsn')),
            ['db_table' => 'sessions', 'lock_mode' => PdoSessionHandler::LOCK_NONE],
        );
        $handler->open('', 'PHPSESSID');
        $handler->write($sessionId, 'payload-xyz');
        $handler->close();

        try {
            self::assertSame(1, (int) $doctrine->fetchOne('SELECT COUNT(*) FROM sessions WHERE sess_id = ?', [$sessionId]));
        } finally {
            $doctrine->executeStatement('DELETE FROM sessions WHERE sess_id = ?', [$sessionId]);
        }
    }

    /**
     * PdoSessionHandler opens its OWN PDO — outside Doctrine — so Doctrine's connection middleware never reaches
     * it (ADR-061 / ADR-066). Compare the live session connection with Doctrine's: every baseline setting must
     * read back the same, and both must be connected to the same database.
     */
    public function testTheSessionConnectionHasTheSameMysqlBaselineAsDoctrineAndTheSameDatabase(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(PdoSessionHandler::class);
        /** @var \PDO $sessionPdo */
        $sessionPdo = (new \ReflectionProperty(PdoSessionHandler::class, 'pdo'))->getValue($handler);
        self::assertInstanceOf(\PDO::class, $sessionPdo, 'the session handler must have been handed a connection');

        /** @var \Doctrine\DBAL\Connection $doctrine */
        $doctrine = self::getContainer()->get('doctrine.dbal.default_connection');

        $settings = 'SELECT @@SESSION.sql_mode, @@SESSION.innodb_lock_wait_timeout, @@SESSION.time_zone, '
            . '@@SESSION.character_set_connection, @@SESSION.collation_connection, DATABASE()';
        $sessionSettings = $sessionPdo->query($settings)->fetch(\PDO::FETCH_NUM);
        self::assertSame(array_map('strval', $doctrine->fetchNumeric($settings)), array_map('strval', $sessionSettings));

        [$sqlMode, $lockWaitTimeout, $timeZone, $charset] = $sessionSettings;
        self::assertStringContainsString('STRICT_ALL_TABLES', $sqlMode);
        self::assertStringContainsString('ANSI_QUOTES', $sqlMode);
        self::assertSame(5, (int) $lockWaitTimeout);
        self::assertSame('+00:00', $timeZone);
        self::assertSame('utf8mb4', $charset);
    }

    /**
     * LOCK_NONE is a deliberate choice (ADR-061, kept by ADR-066): with LOCK_TRANSACTIONAL the session connection
     * holds a transaction open for the whole request alongside Doctrine's own connection, and nothing in this app
     * needs per-session write serialisation. There is ONE handler definition for every environment, so pinning
     * it here pins production too.
     */
    public function testSessionHandlerUsesNoRowLockSoSameRequestDoctrineWritesCannotDeadlockOnIt(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(PdoSessionHandler::class);

        $lockMode = (new \ReflectionProperty(PdoSessionHandler::class, 'lockMode'))->getValue($handler);

        self::assertSame(PdoSessionHandler::LOCK_NONE, $lockMode);
    }

    public function testPdoSessionHandlerIsRegistered(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(PdoSessionHandler::class);
        $this->assertInstanceOf(PdoSessionHandler::class, $handler);
    }

    public function testSessionsTableExists(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions'"
        );
        $this->assertSame('sessions', $row, 'sessions table must exist after migrations');
    }

    public function testSessionsTableHasExpectedColumns(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $columns = TableInfo::columns($conn, 'sessions');
        $names = array_column($columns, 'name');

        $this->assertContains('sess_id', $names);
        $this->assertContains('sess_data', $names);
        $this->assertContains('sess_time', $names);
        $this->assertContains('sess_lifetime', $names);
    }

    public function testSessionsTableAcceptsInsertAndDelete(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        $testId = 'test_session_' . bin2hex(random_bytes(8));
        $conn->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
            [$testId, 'data', time(), 3600]
        );

        $found = $conn->executeQuery('SELECT sess_id FROM sessions WHERE sess_id = ?', [$testId])->fetchOne();
        $this->assertSame($testId, $found);

        $conn->executeStatement('DELETE FROM sessions WHERE sess_id = ?', [$testId]);
        $gone = $conn->executeQuery('SELECT sess_id FROM sessions WHERE sess_id = ?', [$testId])->fetchOne();
        $this->assertFalse($gone);
    }
}
