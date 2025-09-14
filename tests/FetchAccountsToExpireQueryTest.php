<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ExpireChars;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FetchAccountsToExpireQueryTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$mockResults = [true];
    }

    /**
     * @dataProvider provideThresholdCombinations
     */
    public function testQueryBuildsExpectedConditions(int $old, int $new, int $trash): void
    {
        if ($old === 0 && $new === 0 && $trash === 0) {
            Database::$mockResults = [];
        }

        $conditions = [];
        if ($old > 0) {
            $conditions[] = "(laston < '" . date('Y-m-d H:i:s', strtotime("-$old days")) . "')";
        }
        if ($new > 0) {
            $conditions[] = "(laston < '" . date('Y-m-d H:i:s', strtotime("-$new days")) . "' AND level=1 AND dragonkills=0)";
        }
        if ($trash > 0) {
            $conditions[] = "(laston < '" . date('Y-m-d H:i:s', strtotime('-' . ($trash + 1) . ' days')) . "' AND level=1 AND experience < 10 AND dragonkills=0)";
        }

        $expected = null;
        if ($conditions) {
            $expected = 'SELECT login,acctid,dragonkills,level FROM accounts'
                . ' WHERE (superuser&' . NO_ACCOUNT_EXPIRATION . ')=0 AND (' . implode(' OR ', $conditions) . ')';
        }

        $ref = new \ReflectionClass(ExpireChars::class);
        $method = $ref->getMethod('fetchAccountsToExpire');
        $method->setAccessible(true);
        $method->invoke(null, $old, $new, $trash);

        if ($expected === null) {
            $this->assertSame([], Database::$queries);
        } else {
            $this->assertSame($expected, Database::$queries[0]);
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
