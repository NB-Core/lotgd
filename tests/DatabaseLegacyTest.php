<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\EmptyResult;
use PHPUnit\Framework\TestCase;

final class DatabaseLegacyTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        if (class_exists('Lotgd\\Tests\\Stubs\\DoctrineBootstrap', false)) {
            \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        }
    }

    public function testQueryUsesMysqli(): void
    {
        $result = Database::query('SELECT 1');
        $mysqli = Database::getInstance();

        $this->assertSame(['SELECT 1'], $mysqli->queries);
        $this->assertInstanceOf(EmptyResult::class, $result);
    }

    /**
     * Callers do query() -> fetchAssoc() -> freeResult(); whatever query()
     * hands back has to survive that, or the stub reports success on a value
     * the production Database could never return.
     */
    public function testQueryResultIsAcceptedByTheRestOfTheApi(): void
    {
        $result = Database::query('SELECT 1');

        $this->assertFalse(Database::fetchAssoc($result));
        $this->assertTrue(Database::freeResult($result));
    }
}
