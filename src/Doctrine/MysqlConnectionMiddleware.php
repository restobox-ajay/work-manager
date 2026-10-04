<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applies the MySQL connection baseline ({@see MysqlConnectionBaseline}) to every real Doctrine connection.
 *
 * Fails loud, not silent, for anything that connects but is NOT pdo_mysql: the migrations, the DBAL-only
 * tables and the raw SQL (INSERT IGNORE, ON DUPLICATE KEY UPDATE, REPLACE) all assume MySQL (ADR-066).
 */
final class MysqlConnectionMiddleware implements Middleware
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): ConnectionInterface
            {
                $connection = parent::connect($params);

                $native = $connection->getNativeConnection();
                if (!$native instanceof \PDO || $native->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    throw new \LogicException(
                        'This application is MySQL-only (ADR-066). DATABASE_URL must use the "mysql://" scheme '
                        . '(pdo_mysql) — the schema, migrations and raw SQL all assume MySQL 8.',
                    );
                }

                return new class ($connection) extends AbstractConnectionMiddleware {
                    public function __construct(ConnectionInterface $wrappedConnection)
                    {
                        parent::__construct($wrappedConnection);

                        foreach (MysqlConnectionBaseline::STATEMENTS as $statement) {
                            $wrappedConnection->exec($statement);
                        }
                    }
                };
            }
        };
    }
}
