<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use PHPUnit\Framework\TestCase;

final class DatabaseDoctrineTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        require_once __DIR__ . '/Stubs/DoctrineBootstrap.php';
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        \Lotgd\MySQL\Database::getDoctrineConnection();
    }

    public function testQueryUsesDoctrineConnection(): void
    {
        Database::query('SELECT 1');
        $conn = Database::getDoctrineConnection();

        $this->assertSame(['SELECT 1'], $conn->queries);
        $mysqli = Database::getInstance();
        $this->assertSame([], $mysqli->queries);
    }
}

