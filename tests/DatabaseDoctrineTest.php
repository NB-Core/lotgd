<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Tests\Stubs\DoctrineResult;
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

    public function testFetchAssocReturnsDoctrineRow(): void
    {
        $result = Database::query('SELECT 1');
        $row    = Database::fetchAssoc($result);

        $this->assertSame(['ok' => true], $row);
    }

    public function testInsertIdUsesDoctrine(): void
    {
        $id = Database::insertId();

        $this->assertSame('1', $id);
    }

    public function testEscapeUsesDoctrineQuote(): void
    {
        $escaped = Database::escape("O'Reilly");

        $this->assertSame("O\\'Reilly", $escaped);
    }

    public function testNumRowsReturnsDoctrineCount(): void
    {
        $result = Database::query('SELECT 1');

        $this->assertSame(1, Database::numRows($result));
    }

    public function testUnionQueryWrappedInParenthesesReturnsDoctrineResult(): void
    {
        $result = Database::query('(SELECT 1) UNION (SELECT 2)');

        $this->assertInstanceOf(DoctrineResult::class, $result);
    }

    public function testAffectedRowsAfterStatement(): void
    {
        Database::query('UPDATE test SET x=1');

        $this->assertSame(1, Database::affectedRows());
    }

    public function testFreeResultHandlesDoctrineResult(): void
    {
        $result = Database::query('SELECT 1');

        $this->assertTrue(Database::freeResult($result));
    }

    public function testTableExistsUsesDoctrineSchemaManager(): void
    {
        $this->assertTrue(Database::tableExists('accounts'));
    }
}
