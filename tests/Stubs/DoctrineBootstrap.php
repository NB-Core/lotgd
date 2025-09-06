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
    public array $params = ['charset' => 'utf8mb4'];

    public function executeQuery(string $sql): DoctrineResult
    {
        $this->queries[] = $sql;
        return new DoctrineResult([["ok" => true]]);
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $this->queries[] = $sql;
        return [];
    }

    public function executeStatement(string $sql, array $params = []): int
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
