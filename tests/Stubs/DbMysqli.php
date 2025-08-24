<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

class DbMysqli
{
    public array $queries = [];

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        return 'mysql_result';
    }

    public function connect($host, $user, $pass)
    {
        return true;
    }

    public function selectDb($dbname)
    {
        return true;
    }

    public function fetchAssoc($result): array
    {
        return ['ok' => true];
    }

    public function insertId(): int
    {
        return 5;
    }

    public function numRows($result): int
    {
        return 0;
    }

    public function affectedRows(): int
    {
        return 1;
    }

    public function error(): string
    {
        return '';
    }
}

class_alias(DbMysqli::class, 'Lotgd\\MySQL\\DbMysqli');
