<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Lotgd\ExpireChars;
use Lotgd\MySQL\Database as CoreDatabase;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class FetchAccountsToExpireQueryTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$mockResults = [];
        CoreDatabase::resetDoctrineConnection();
        $connection = CoreDatabase::getDoctrineConnection();
        $connection->queries = [];
        $connection->executeQueryParams = [];
        $connection->executeQueryTypes = [];
    }

    /**
     * @dataProvider provideThresholdCombinations
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideThresholdCombinations')]
    public function testQueryBuildsExpectedConditions(int $old, int $new, int $trash): void
    {
        if ($old === 0 && $new === 0 && $trash === 0) {
            Database::$mockResults = [];
        }

        $now = new DateTimeImmutable('now');
        $conditions = [];
        if ($old > 0) {
            $conditions[] = "(laston < '" . $now->modify("-$old days")->format('Y-m-d H:i:s') . "')";
        }
        if ($new > 0) {
            $conditions[] = "(laston < '" . $now->modify("-$new days")->format('Y-m-d H:i:s') . "' AND level=1 AND dragonkills=0)";
        }
        if ($trash > 0) {
            $conditions[] = "(laston < '" . $now->modify('-' . ($trash + 1) . ' days')->format('Y-m-d H:i:s') . "' AND level=1 AND experience < 10 AND dragonkills=0)";
        }

        ExpireChars::fetchAccountsToExpireForTests($old, $new, $trash, $now);
        $connection = CoreDatabase::getDoctrineConnection();

        if ($conditions === []) {
            $this->assertSame([], $connection->queries);
        } else {
            $this->assertNotEmpty($connection->queries);
            $sql = $connection->queries[0];
            $params = $connection->executeQueryParams[0] ?? [];
            $types = $connection->executeQueryTypes[0] ?? [];

            $this->assertStringContainsString('SELECT login,acctid,dragonkills,level FROM accounts', $sql);
            $this->assertStringContainsString('(superuser & :noAccountExpiration) = 0', $sql);
            $this->assertSame(NO_ACCOUNT_EXPIRATION, $params['noAccountExpiration'] ?? null);
            $this->assertSame(ParameterType::INTEGER, $types['noAccountExpiration'] ?? null);
        }
    }

    /**
     * @return iterable<string,array{int,int,int}>
     */
    public static function provideThresholdCombinations(): iterable
    {
        yield 'old only' => [45, 0, 0];
        yield 'new only' => [0, 10, 0];
        yield 'trash only' => [0, 0, 1];
        yield 'old and new' => [45, 10, 0];
        yield 'old and trash' => [45, 0, 1];
        yield 'new and trash' => [0, 10, 1];
        yield 'all' => [45, 10, 1];
        yield 'none' => [0, 0, 0];
    }
}
