<?php

declare(strict_types=1);

namespace App\Tests\Functional\Meta;

use App\Tests\Support\ScratchDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Issue #38: the SQL written by `doctrine:migrations:migrate --dry-run --write-sql` for a FRESH database must
 * replay cleanly — it is what an operator would review and apply by hand. Migrations that decide "create or not"
 * by introspecting the live database break this: in a dry run nothing executes, so two such migrations both see
 * no table and both emit the CREATE.
 *
 * Runs the real console command in a subprocess against an empty scratch database, then executes the written
 * SQL, statement by statement, against another empty one. Doctrine's own metadata table is not part of the
 * written SQL (only its INSERTs are), so it is created first, exactly as `doctrine:migrations:sync-metadata-storage`
 * would.
 */
final class MigrationDryRunSqlTest extends TestCase
{
    private ScratchDatabase $source;
    private ScratchDatabase $replay;
    private string $sqlFile;

    protected function setUp(): void
    {
        $this->source = ScratchDatabase::create('dryrun_src');
        $this->replay = ScratchDatabase::create('dryrun_replay');
        $this->sqlFile = sys_get_temp_dir() . '/migration-dry-run-' . bin2hex(random_bytes(4)) . '.sql';
    }

    protected function tearDown(): void
    {
        $this->source->drop();
        $this->replay->drop();
        @unlink($this->sqlFile);
    }

    public function testTheDryRunSqlForAFreshDatabaseReplaysCleanly(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $sqlFile = $this->sqlFile;

        $process = proc_open(
            ['php', 'bin/console', 'doctrine:migrations:migrate', '--dry-run', '--write-sql=' . $sqlFile, '--no-interaction'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectRoot,
            // The inherited environment plus overrides, not a bare PATH-only one: on Windows a child without
            // SystemRoot cannot initialise Winsock, so every TCP connect (MySQL) fails with error 2002.
            array_merge(getenv(), [
                'APP_ENV'      => 'dev',
                'DATABASE_URL' => $this->source->url,
                'PATH'         => getenv('PATH') ?: '/usr/bin:/bin',
            ]),
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, 'the dry run itself failed: ' . $output);
        self::assertFileExists($sqlFile);

        $sql = (string) file_get_contents($sqlFile);
        self::assertStringContainsString('CREATE TABLE', $sql, 'the dry run must have written the schema');

        $replay = $this->replay->connection();
        $replay->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) NOT NULL, executed_at DATETIME DEFAULT NULL, execution_time INT DEFAULT NULL, PRIMARY KEY (version))');

        // One statement at a time: a multi-statement exec() would report only the FIRST statement's error.
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
            $statement = trim((string) preg_replace('/^--.*$/m', '', $statement));
            if ($statement === '') {
                continue;
            }
            try {
                $replay->executeStatement($statement);
            } catch (\Doctrine\DBAL\Exception $e) {
                self::fail('the written migration SQL does not replay on a fresh database: ' . $e->getMessage());
            }
        }

        self::assertSame(1, (int) $replay->fetchOne("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_delivery'"));
        $replay->close();
    }
}
