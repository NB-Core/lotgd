<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use PHPUnit\Framework\TestCase;

final class DatabaseLegacyTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
    }

    public function testQueryUsesMysqli(): void
    {
        $result = Database::query('SELECT 1');
        $mysqli = Database::getInstance();

        $this->assertSame(['SELECT 1'], $mysqli->queries);
        $this->assertSame('mysql_result', $result);
    }
}

