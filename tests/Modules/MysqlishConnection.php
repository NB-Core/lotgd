<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

/**
 * A pdo_sqlite connection that answers the MySQL-only statements the module
 * sends, and counts commits.
 */
final class MysqlishConnection extends Connection
{
    public bool $failPreferenceWrites = false;
    public bool $failRollback = false;
    public int $commits = 0;
    /** @var list<string> Tables information_schema reports on an engine that cannot roll back. */
    public array $nonTransactionalTables = [];

    public function executeQuery(
        string $sql,
        array $params = [],
        array $types = [],
        ?QueryCacheProfile $qcp = null,
    ): Result {
        // SQLite locks the whole database for a write transaction anyway.
        $sql = (string) preg_replace('/\s+FOR UPDATE$/i', '', trim($sql));
        if (preg_match('/^DESCRIBE\s+(\w+)$/i', $sql, $match) === 1) {
            $sql = "SELECT name AS Field, CASE WHEN type = 'INTEGER' THEN 'int(11)' ELSE 'varchar(255)' END AS Type"
                . " FROM pragma_table_info('{$match[1]}')";
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        if (str_contains($query, 'information_schema.ENGINES')) {
            return array_map(
                fn (string $table): array => [
                    'name' => $table,
                    'transactions' => in_array($table, $this->nonTransactionalTables, true) ? 'NO' : 'YES',
                ],
                array_column($this->fetchAllAssociative("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name')
            );
        }
        if (preg_match('/^SHOW INDEX FROM/i', trim($query)) === 1) {
            return array_map(
                static fn (int $seq, string $column): array => [
                    'Non_unique' => 0, 'Key_name' => 'pref', 'Seq_in_index' => $seq, 'Column_name' => $column, 'Sub_part' => null,
                ],
                [1, 2, 3],
                ['userid', 'modulename', 'setting']
            );
        }

        return parent::fetchAllAssociative($query, $params, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            if ($this->failPreferenceWrites) {
                throw new \RuntimeException('The preference could not be written.');
            }
            $sql = str_replace(
                'ON DUPLICATE KEY UPDATE value = VALUES(value)',
                'ON CONFLICT (userid, modulename, setting) DO UPDATE SET value = excluded.value',
                $sql
            );
        }

        return parent::executeStatement($sql, $params, $types);
    }

    public function rollBack(): void
    {
        if ($this->failRollback) {
            throw new \RuntimeException('The connection was lost.');
        }
        parent::rollBack();
    }

    public function commit(): void
    {
        parent::commit();
        $this->commits++;
    }
}
