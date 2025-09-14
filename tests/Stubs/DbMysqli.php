<?php

declare(strict_types=1);

namespace {
    /**
     * Some installer scripts reference the legacy $DB variable to detect the
     * database driver.  Define it here so that any such checks do not trigger
     * warnings when this stub is auto-prepended.
     */
    $DB = 'mysql';
}

namespace Lotgd\MySQL {

/**
 * Simple in-memory stub of the DbMysqli adapter used during tests.
 * It records connection details and queries without performing any
 * real database operations.
 */
    class DbMysqli
    {
        /** @var array<int, string> */
        public array $queries = [];

        /** @var array<int, string> */
        public array $connectArgs = [];

        public ?string $selectedDb = null;

        public function connect(string $h, string $u, string $p): bool
        {
            $this->connectArgs = [$h, $u, $p];
            return true;
        }

        public function pconnect(string $h, string $u, string $p): bool
        {
            return $this->connect($h, $u, $p);
        }

        public function selectDb(string $db): bool
        {
            $this->selectedDb = $db;
            return true;
        }

        public function setCharset(string $charset): bool
        {
            return true;
        }

        public function query(string $sql)
        {
            $this->queries[] = $sql;
            return 'mysql_result';
        }

        public function fetchAssoc($result): array
        {
            return [];
        }

        public function insertId(): int
        {
            return 0;
        }

        public function numRows($r): int
        {
            return 0;
        }

        public function affectedRows(): int
        {
            return 0;
        }

        public function error(): string
        {
            return '';
        }

        public function real_escape_string($string): string
        {
            // Mimic MySQLi real_escape_string for test purposes
            return addslashes($string);
        }

        public function freeResult($result): bool
        {
            return true;
        }

        public function tableExists(string $tablename): bool
        {
            return false;
        }

        public function getServerVersion(): string
        {
            return 'stub';
        }
    }

}

namespace {
// Maintain backwards compatibility for tests that import the stub via the
// Lotgd\Tests\Stubs namespace.
    class_alias(\Lotgd\MySQL\DbMysqli::class, 'Lotgd\\Tests\\Stubs\\DbMysqli');
}
