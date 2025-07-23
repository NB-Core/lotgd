<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use PHPUnit\Framework\TestCase;

final class DatabaseDoctrineTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(DoctrineBootstrap::class);
    }

    public function testQueryUsesDoctrineConnection(): void
    {
        $conn = Database::getDoctrineConnection();
        Database::query('SELECT 1');

        $this->assertSame(['SELECT 1'], $conn->queries);
        $mysqli = Database::getInstance();
        $this->assertSame([], $mysqli->queries);
    }
}

