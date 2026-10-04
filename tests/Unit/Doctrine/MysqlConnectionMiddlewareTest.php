<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\MysqlConnectionMiddleware;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\ServerVersionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * This app is MySQL-only (ADR-066): a DATABASE_URL pointed at any other engine must fail loudly at the first
 * connection, not silently run without the baseline. Exercised against stand-in drivers so no server is needed;
 * the live baseline itself is pinned by the functional MysqlConnectionBaselineTest.
 */
final class MysqlConnectionMiddlewareTest extends TestCase
{
    /** @return array<string, array{object}> */
    public static function nonMysqlNativeConnections(): array
    {
        return [
            'not a PDO at all' => [new \stdClass()],
            'a PDO for another engine' => [new \PDO('sqlite::memory:')],
        ];
    }

    #[DataProvider('nonMysqlNativeConnections')]
    public function testConnectingThroughAnythingButPdoMysqlThrows(object $native): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MySQL-only');

        (new MysqlConnectionMiddleware())->wrap(self::driverReturning($native))->connect([]);
    }

    private static function driverReturning(object $native): DriverInterface
    {
        return new class ($native) implements DriverInterface {
            public function __construct(private readonly object $native)
            {
            }

            public function connect(array $params): ConnectionInterface
            {
                return new class ($this->native) implements ConnectionInterface {
                    public function __construct(private readonly object $native)
                    {
                    }

                    public function prepare(string $sql): never
                    {
                        throw new \LogicException('unused in this test');
                    }

                    public function query(string $sql): never
                    {
                        throw new \LogicException('unused in this test');
                    }

                    public function quote(string $value): string
                    {
                        return $value;
                    }

                    public function exec(string $sql): int|string
                    {
                        throw new \LogicException('the baseline must not run on a refused connection');
                    }

                    public function lastInsertId(): int|string
                    {
                        return 0;
                    }

                    public function beginTransaction(): void
                    {
                    }

                    public function commit(): void
                    {
                    }

                    public function rollBack(): void
                    {
                    }

                    public function getServerVersion(): string
                    {
                        return '1.0';
                    }

                    public function getNativeConnection(): object
                    {
                        return $this->native;
                    }
                };
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): never
            {
                throw new \LogicException('unused in this test');
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                throw new \LogicException('unused in this test');
            }
        };
    }
}
