<?php

declare(strict_types=1);

namespace Lotgd\Tests\Repository;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database as CoreDatabase;
use Lotgd\Repository\ClanRepository;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class ClanRepositoryDoctrineBindingTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        CoreDatabase::resetDoctrineConnection();
        $connection = CoreDatabase::getDoctrineConnection();
        $connection->queries = [];
        $connection->executeQueryParams = [];
        $connection->executeQueryTypes = [];
        $connection->executeStatements = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['accounts_table']);
    }

    public function testFetchAccountNameUsesTypedParameter(): void
    {
        $connection = CoreDatabase::getDoctrineConnection();
        $GLOBALS['accounts_table'][17] = ['name' => 'Alice'];

        $name = ClanRepository::fetchAccountName(17);

        self::assertSame('Alice', $name);
        self::assertSame(['acctid' => 17], $connection->fetchAssociativeLog[0]['params'] ?? []);
        self::assertSame(['acctid' => ParameterType::INTEGER], $connection->fetchAssociativeLog[0]['types'] ?? []);
    }

    public function testPromoteToLeaderUsesExecuteStatementWithTypedParameters(): void
    {
        $connection = CoreDatabase::getDoctrineConnection();

        ClanRepository::promoteToLeader(99);

        $statement = $connection->executeStatements[0] ?? null;
        self::assertNotNull($statement);
        self::assertSame(99, $statement['params']['acctid'] ?? null);
        self::assertSame(ParameterType::INTEGER, $statement['types']['acctid'] ?? null);
        self::assertSame(ParameterType::INTEGER, $statement['types']['leaderRank'] ?? null);
    }
}
