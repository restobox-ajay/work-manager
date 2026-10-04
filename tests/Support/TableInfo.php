<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Column metadata for a table, read from MySQL's information_schema (ADR-066) — the MySQL stand-in for the
 * `PRAGMA table_info(...)` these schema tests used under SQLite. Rows keep that familiar shape so the
 * assertions read the same: `name`, `type`, `notnull` ('1' / '0'), `dflt_value`, `pk` ('1' / '0').
 */
final class TableInfo
{
    /**
     * @return list<array{name: string, type: string, notnull: string, dflt_value: ?string, pk: string}>
     */
    public static function columns(Connection $connection, string $table): array
    {
        /** @var list<array{name: string, type: string, notnull: string, dflt_value: ?string, pk: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            "SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type,
                    CASE WHEN IS_NULLABLE = 'NO' THEN '1' ELSE '0' END AS notnull,
                    COLUMN_DEFAULT AS dflt_value,
                    CASE WHEN COLUMN_KEY = 'PRI' THEN '1' ELSE '0' END AS pk
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION",
            [$table],
        );

        return $rows;
    }
}
