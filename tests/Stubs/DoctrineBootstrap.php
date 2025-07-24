<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

class DoctrineResult
{
    private array $rows;

    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function fetchAssociative()
    {
        return array_shift($this->rows) ?: false;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function free(): void
    {
    }
}

class DoctrineConnection
{
    public array $queries = [];

    public function executeQuery(string $sql): DoctrineResult
    {
        $this->queries[] = $sql;
        return new DoctrineResult([["ok" => true]]);
    }

    public function executeStatement(string $sql): int
    {
        $this->queries[] = $sql;
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
        $this->entity = new class {
            public array $data = [];
            public function __call(string $name, array $args)
            {
                if (str_starts_with($name, 'set')) {
                    $field = lcfirst(substr($name, 3));
                    $this->data[$field] = $args[0] ?? null;
                    return $this;
                }
                if (str_starts_with($name, 'get')) {
                    $field = lcfirst(substr($name, 3));
                    return $this->data[$field] ?? null;
                }
                return null;
            }
        };
        $this->entity->setAcctid($id);
        return $this->entity;
    }

    public function flush(): void
    {
        $this->connection->executeQuery('FLUSH');
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

if (!class_exists('Lotgd\\Doctrine\\Bootstrap')) {
    class_alias(DoctrineBootstrap::class, 'Lotgd\\Doctrine\\Bootstrap');
}
if (!class_exists('Doctrine\\DBAL\\Connection')) {
    class_alias(DoctrineConnection::class, 'Doctrine\\DBAL\\Connection');
}
if (!class_exists('Doctrine\\DBAL\\Result')) {
    class_alias(DoctrineResult::class, 'Doctrine\\DBAL\\Result');
}

