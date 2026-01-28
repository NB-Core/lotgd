<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

class DoctrineResult
{
    private array $rows;
    private array $originalRows;
    private int|string|null $rowCountOverride;

    public function __construct(array $rows = [], int|string|null $rowCountOverride = null)
    {
        $this->rows = $rows;
        $this->originalRows = $rows;
        $this->rowCountOverride = $rowCountOverride;
    }

    public function fetchAssociative()
    {
        return array_shift($this->rows) ?: false;
    }

    public function rowCount(): int|string
    {
        if ($this->rowCountOverride !== null) {
            return $this->rowCountOverride;
        }

        return count($this->originalRows);
    }

    public function fetchAllAssociative(): array
    {
        $rows = $this->rows;
        $this->rows = [];

        return $rows;
    }

    public function free(): void
    {
    }
}

class DoctrineConnection
{
    public array $queries = [];
    public array $params = ['charset' => 'utf8mb4'];
    /** @var array<int,int> */
    public array $countResults = [];
    /** @var array<int, int|string|null> */
    public array $rowCountOverrides = [];
    public array $lastInsert = [];
    public array $lastDelete = [];
    public array $fetchAllResults = [];
    public array $lastFetchAllParams = [];
    public array $lastFetchAllTypes = [];
    public array $fetchAssociativeResults = [];
    public array $lastFetchAssociativeParams = [];
    public array $lastFetchAssociativeTypes = [];
    public array $fetchAssociativeLog = [];
    public array $executeStatements = [];
    public array $lastExecuteStatementParams = [];
    public array $lastExecuteStatementTypes = [];
    public array $executeQueryParams = [];
    public array $executeQueryTypes = [];

    private function makeResult(array $rows): DoctrineResult
    {
        $override = null;
        if (!empty($this->rowCountOverrides)) {
            $override = array_shift($this->rowCountOverrides);
        }

        return new DoctrineResult($rows, $override);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function executeQuery(string $sql, array $params = [], array $types = []): DoctrineResult
    {
        $this->queries[] = $sql;
        $this->executeQueryParams[] = $params;
        $this->executeQueryTypes[] = $types;

        $accountsTable = Database::prefix('accounts');
        if (preg_match("/SELECT\s+prefs,emailaddress\s+FROM\s+" . preg_quote($accountsTable, '/') . "\s+WHERE\s+acctid=\'?([0-9]+)\'?/i", $sql, $matches)) {
            global $accounts_table;
            $acctid = (int) $matches[1];
            $row = $accounts_table[$acctid] ?? ['prefs' => '', 'emailaddress' => ''];

            return $this->makeResult([$row]);
        }

        if (preg_match("/SELECT\s+prefs\s+FROM\s+" . preg_quote($accountsTable, '/') . "\s+WHERE\s+acctid=\'?([0-9]+)\'?/i", $sql, $matches)) {
            global $accounts_table;
            $acctid = (int) $matches[1];
            $prefs = $accounts_table[$acctid]['prefs'] ?? '';

            return $this->makeResult([['prefs' => $prefs]]);
        }

        if (preg_match("/SELECT\s+name\s+FROM\s+" . preg_quote($accountsTable, '/') . "\s+WHERE\s+acctid=\'?([0-9]+)\'?/i", $sql, $matches)) {
            global $accounts_table;
            $acctid = (int) $matches[1];
            $name = $accounts_table[$acctid]['name'] ?? '';

            return $this->makeResult([['name' => $name]]);
        }

        $mailTable = Database::prefix('mail');
        if (preg_match("/SELECT\s+count\\(messageid\\)\s+AS\s+count\s+FROM\s+" . preg_quote($mailTable, '/') . "\s+WHERE\s+msgto=\'?([0-9]+)\'?([^;]*)/i", $sql, $matches)) {
            global $mail_table;
            $acctid = (int) $matches[1];
            $onlyUnread = preg_match('/seen\s*=\s*0/i', $matches[2]) === 1;
            $count = 0;
            foreach ($mail_table as $row) {
                if ((int) ($row['msgto'] ?? 0) === $acctid && (! $onlyUnread || (int) ($row['seen'] ?? 0) === 0)) {
                    $count++;
                }
            }

            return $this->makeResult([['count' => $count]]);
        }

        if (preg_match("/SELECT\s+count\\(messageid\\)\s+AS\s+count\s+FROM\s+" . preg_quote($mailTable, '/') . "\s+WHERE\s+msgto\s*=\s*:msgto([^;]*)/i", $sql, $matches)) {
            global $mail_table;
            $acctid = (int) ($params['msgto'] ?? 0);
            $onlyUnread = preg_match('/seen\s*=\s*0/i', $matches[1]) === 1;
            $count = 0;
            foreach ($mail_table as $row) {
                if ((int) ($row['msgto'] ?? 0) === $acctid && (! $onlyUnread || (int) ($row['seen'] ?? 0) === 0)) {
                    $count++;
                }
            }

            return $this->makeResult([['count' => $count]]);
        }

        if (preg_match('/SELECT\s+(.+?)\s+FROM\s+' . preg_quote($mailTable, '/') . '\b/i', $sql, $matches)) {
            global $mail_table;
            $columnsExpr = trim($matches[1]);
            $rows = $mail_table ?? [];

            if (stripos($sql, 'WHERE') !== false && preg_match('/msgto\s*=\s*(?::([a-z0-9_]+)|\?)/i', $sql, $whereMatches)) {
                $paramKey = $whereMatches[1] ?? 0;
                $value = $whereMatches[1] !== null ? ($params[$paramKey] ?? null) : ($params[0] ?? null);

                if ($value !== null) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => (int) ($row['msgto'] ?? 0) === (int) $value
                    ));
                }
            }

            $selectedColumns = null;
            if ($columnsExpr !== '*') {
                $selectedColumns = array_map(
                    static function (string $column): string {
                        $column = preg_replace('/\s+AS\s+.*/i', '', trim($column));
                        return str_replace('`', '', $column);
                    },
                    explode(',', $columnsExpr)
                );
            }

            $results = [];
            foreach ($rows as $row) {
                if ($selectedColumns === null) {
                    $results[] = $row;
                    continue;
                }

                $selected = [];
                foreach ($selectedColumns as $column) {
                    $selected[$column] = $row[$column] ?? null;
                }
                $results[] = $selected;
            }

            return $this->makeResult($results);
        }

        if (preg_match("/SELECT\s+\*\s+FROM\s+" . preg_quote(Database::prefix('nastywords'), '/') . "\s+WHERE\s+type='(good|nasty)'/i", $sql)) {
            return $this->makeResult([['words' => '']]);
        }

        if (stripos($sql, 'count(') !== false) {
            $value = array_shift($this->countResults);
            if ($value === null) {
                $value = 0;
            }

            return $this->makeResult([["total_count" => $value]]);
        }

        $rows = [];
        if (!empty(Database::$mockResults)) {
            $rows = array_shift(Database::$mockResults);
        } elseif (!empty($this->fetchAllResults)) {
            $rows = array_shift($this->fetchAllResults);
        } else {
            $rows = [["ok" => true]];
        }

        if (!is_array($rows)) {
            $rows = [];
        }

        return $this->makeResult($rows);
    }

    public function fetchAllAssociative(string $sql, array $params = [], array $types = []): array
    {
        $this->queries[] = $sql;
        $this->lastFetchAllParams = $params;
        $this->lastFetchAllTypes = $types;

        if (!empty(Database::$mockResults)) {
            $rows = array_shift(Database::$mockResults);

            return is_array($rows) ? $rows : [];
        }

        if (!empty($this->fetchAllResults)) {
            return array_shift($this->fetchAllResults);
        }

        return [];
    }

    public function fetchAssociative(string $sql, array $params = [], array $types = []): array|false
    {
        $this->queries[] = $sql;
        $this->lastFetchAssociativeParams = $params;
        $this->lastFetchAssociativeTypes = $types;
        $this->fetchAssociativeLog[] = [
            'sql'    => $sql,
            'params' => $params,
            'types'  => $types,
        ];

        $accountsTable = Database::prefix('accounts');

        if (preg_match('/SELECT\s+prefs\s*,\s*emailaddress\s+FROM\s+' . preg_quote($accountsTable, '/') . '\s+WHERE\s+acctid\s*=\s*:acctid/i', $sql)) {
            global $accounts_table;
            $acctid = (int) ($params['acctid'] ?? 0);
            $row = $accounts_table[$acctid] ?? [];

            return [
                'prefs' => $row['prefs'] ?? '',
                'emailaddress' => $row['emailaddress'] ?? '',
            ];
        }

        if (preg_match('/SELECT\s+name\s+FROM\s+' . preg_quote($accountsTable, '/') . '\s+WHERE\s+acctid\s*=\s*:acctid/i', $sql)) {
            global $accounts_table;
            $acctid = (int) ($params['acctid'] ?? 0);
            $row = $accounts_table[$acctid] ?? [];

            return ['name' => $row['name'] ?? ''];
        }

        if (!empty($this->fetchAssociativeResults)) {
            $row = array_shift($this->fetchAssociativeResults);

            return is_array($row) ? $row : false;
        }

        if (!empty(Database::$mockResults)) {
            $rows = array_shift(Database::$mockResults);
            if (is_array($rows)) {
                return array_is_list($rows) ? ($rows[0] ?? false) : $rows;
            }
        }

        return false;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        $this->queries[] = $sql;
        $this->executeStatements[] = [
            'sql'    => $sql,
            'params' => $params,
            'types'  => $types,
        ];
        $this->lastExecuteStatementParams = $params;
        $this->lastExecuteStatementTypes = $types;
        if (preg_match('/^INSERT INTO\s+`?mail`?/i', $sql)) {
            global $mail_table;
            $mail_table ??= [];
            $from   = $params['msgfrom'] ?? ($params[0] ?? 0);
            $to     = $params['msgto'] ?? ($params[1] ?? 0);
            $subject = $params['subject'] ?? ($params[2] ?? '');
            $body    = $params['body'] ?? ($params[3] ?? '');
            $sent    = $params['sent'] ?? ($params[4] ?? '');
            $mail_table[] = [
                'messageid' => count($mail_table) + 1,
                'msgfrom'   => $from,
                'msgto'     => $to,
                'subject'   => $subject,
                'body'      => $body,
                'sent'      => $sent,
                'seen'      => 0,
            ];
            Database::setAffectedRows(1);

            return 1;
        }
        $table = null;
        if (preg_match('/^INSERT INTO\s+`?([^`\s(]+)`?/i', $sql, $matches)) {
            $table = strtolower($matches[1]);
        }

        $isExtended = false;
        if ($table !== null) {
            if (str_ends_with($table, 'settings_extended')) {
                $isExtended = true;
            } elseif (! str_ends_with($table, 'settings')) {
                $table = null;
            }
        }

        if ($table !== null) {
            $setting = $params['setting'] ?? ($params[0] ?? null);
            $value   = $params['value'] ?? ($params[1] ?? null);

            if ($setting === null) {
                return 0;
            }

            if ($isExtended) {
                $target =& Database::$settings_extended_table;
            } else {
                $target =& Database::$settings_table;
            }

            $exists   = array_key_exists($setting, $target);
            $previous = $target[$setting] ?? null;
            $target[$setting] = $value;

            if (! $exists) {
                $affected = 1;
            } elseif ($previous !== $value) {
                $affected = 2;
            } else {
                $affected = 0;
            }

            Database::setAffectedRows($affected);

            return $affected;
        }

        return 1;
    }

    public function delete(string $table, array $criteria, array $types = []): int
    {
        $this->lastDelete = [
            'table'    => $table,
            'criteria' => $criteria,
            'types'    => $types,
        ];

        $conditions = [];
        foreach ($criteria as $column => $_value) {
            $conditions[] = $column . ' = ?';
        }

        $where = $conditions ? implode(' AND ', $conditions) : '1 = 1';
        $this->queries[] = sprintf('DELETE FROM %s WHERE %s', $table, $where);

        return 1;
    }

    public function insert(string $table, array $data, array $types = []): int
    {
        $this->lastInsert = [
            'table' => $table,
            'data'  => $data,
            'types' => $types,
        ];

        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $this->queries[] = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $columns, $placeholders);

        return 1;
    }

    public function lastInsertId(): string
    {
        return '1';
    }

    public function quote(string $string): string
    {
        return "'" . addslashes($string) . "'";
    }

    public function createSchemaManager()
    {
        return new class {
            public function tablesExist(array $tables): bool
            {
                return true;
            }
        };
    }

    public function getParams(): array
    {
        return $this->params;
    }
}

class DoctrineEntityManager
{
    public DoctrineConnection $connection;
    public ?object $entity = null;

    public function __construct(DoctrineConnection $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): DoctrineConnection
    {
        return $this->connection;
    }

    public function find(string $class, $id)
    {
        $this->entity = new $class();
        if (method_exists($this->entity, 'setAcctid')) {
            $this->entity->setAcctid($id);
        }
        return $this->entity;
    }

    public function flush(): void
    {
        // Simulate persisting the entity's state to a mock storage
        if ($this->entity) {
            $ref  = new \ReflectionClass($this->entity);
            $data = [];
            foreach ($ref->getProperties() as $prop) {
                $prop->setAccessible(true);
                $data[$prop->getName()] = $prop->getValue($this->entity);
            }
            $this->connection->queries[] = sprintf(
                'PERSIST ENTITY: %s',
                json_encode($data)
            );
        }
    }
}

class DoctrineBootstrap
{
    public static ?DoctrineConnection $conn = null;

    public static function getEntityManager(): DoctrineEntityManager
    {
        if (!self::$conn) {
            self::$conn = new DoctrineConnection();
        }

        return new DoctrineEntityManager(self::$conn);
    }
}

if (!class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
    class_alias(DoctrineBootstrap::class, 'Lotgd\\Doctrine\\Bootstrap');
}
if (!class_exists('Doctrine\\DBAL\\Connection')) {
    class_alias(DoctrineConnection::class, 'Doctrine\\DBAL\\Connection');
}
if (!class_exists('Doctrine\\DBAL\\Result')) {
    class_alias(DoctrineResult::class, 'Doctrine\\DBAL\\Result');
}
