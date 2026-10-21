<?php

declare(strict_types=1);

namespace Lotgd\Tests\MySQL;

use Lotgd\MySQL\StorageEngine;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

final class StorageEngineTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
    }

    public function testAMyIsamOrMissingTableIsNamed(): void
    {
        $this->connection->queryResultsByPattern = ['/information_schema\.TABLES/' => [
            ['TABLE_NAME' => 'accounts', 'ENGINE' => 'MyISAM'],
            ['TABLE_NAME' => 'mail', 'ENGINE' => 'InnoDB'],
        ]];

        self::assertSame(['accounts', 'action_log'], StorageEngine::nonTransactional(['accounts', 'action_log', 'mail']));
    }

    public function testInnoDbTablesPass(): void
    {
        self::assertSame([], StorageEngine::nonTransactional(['accounts', 'mail']));
    }
}
