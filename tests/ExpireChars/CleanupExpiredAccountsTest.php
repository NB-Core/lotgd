<?php

declare(strict_types=1);

namespace Lotgd\Tests\ExpireChars;

use Lotgd\ExpireChars;
use Lotgd\MySQL\Database as CoreDatabase;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\ExpireCharsEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * cleanupExpiredAccounts() deletes player accounts, so the question each case
 * asks is the same one: under what circumstances does the DELETE actually run,
 * and what does the operator get told about it.
 *
 * This replaces four classes that each stood up the same three collaborators
 * with their own copy of the same eval() strings. The copies had drifted --
 * two recorded GameLog entries and two discarded them -- so the success and
 * failure paths could not live in one file. They share
 * Stubs\ExpireCharsEnvironment now, and what varies is a flag.
 *
 * Runs each test in its own process: the environment defines real classes in
 * the Lotgd namespace, and a class cannot be redefined.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CleanupExpiredAccountsTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
        Database::$affected_rows = 0;
        CoreDatabase::resetDoctrineConnection();
        $connection = CoreDatabase::getDoctrineConnection();
        $connection->queries = [];
        $connection->executeStatementResults = [];

        ExpireCharsEnvironment::install();
    }

    /**
     * Queue one expired account for cleanupExpiredAccounts() to find.
     */
    private static function expectOneExpiredAccount(string $login = 'test', int $dragonkills = 0, int $level = 1): void
    {
        Database::$mockResults = [
            [['acctid' => 1, 'login' => $login, 'dragonkills' => $dragonkills, 'level' => $level]],
        ];
    }

    public function testSuccessfulCleanupDeletesTheAccountInATransaction(): void
    {
        self::expectOneExpiredAccount();
        CoreDatabase::getDoctrineConnection()->executeStatementResults = [1];

        ExpireChars::cleanupExpiredAccountsForTests();

        $queries = CoreDatabase::getDoctrineConnection()->queries;

        // Asserted by content rather than by position: the old test pinned
        // $queries[1], [2] and [3], so any query added anywhere ahead of the
        // delete broke it without anything being wrong.
        self::assertContains('START TRANSACTION', $queries);
        self::assertContains('COMMIT', $queries);
        self::assertNotContains('ROLLBACK', $queries);

        $deletes = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'DELETE FROM accounts WHERE acctid = :acctid')
        ));
        self::assertCount(1, $deletes, 'exactly one account should be deleted');

        self::assertTrue(\Lotgd\PlayerFunctions::$cleanupCalled, 'charCleanup runs before the delete');
    }

    /**
     * charCleanup() strips an account's comments, cached output and clan
     * membership. If that fails the row must stay, or the game is left with
     * orphaned rows pointing at an account that no longer exists.
     */
    public function testFailedCleanupLeavesTheAccountAlone(): void
    {
        \Lotgd\PlayerFunctions::$cleanupSucceeds = false;
        self::expectOneExpiredAccount();

        ExpireChars::cleanupExpiredAccountsForTests();

        $queries = CoreDatabase::getDoctrineConnection()->queries;

        self::assertTrue(\Lotgd\PlayerFunctions::$cleanupCalled, 'positive control: cleanup was attempted');
        self::assertEmpty(
            array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'DELETE FROM accounts')),
            'no account may be deleted once cleanup has failed'
        );
        self::assertContains('ROLLBACK', $queries, 'the transaction must be undone');
        self::assertNotContains('COMMIT', $queries, 'and never committed');
    }

    public function testADeleteThatAffectsNoRowsRollsBackAndIsLoggedAsAnError(): void
    {
        self::expectOneExpiredAccount();
        CoreDatabase::getDoctrineConnection()->executeStatementResults = [0];

        ExpireChars::cleanupExpiredAccountsForTests();

        self::assertSame(
            [['expiration', 'Failed to delete account 1: deletion failed', 'error']],
            \Lotgd\GameLog::$entries
        );
        self::assertContains('ROLLBACK', CoreDatabase::getDoctrineConnection()->queries);
    }

    public function testASuccessfulDeleteIsLoggedAsInfoAndCommitted(): void
    {
        self::expectOneExpiredAccount();
        CoreDatabase::getDoctrineConnection()->executeStatementResults = [1];

        ExpireChars::cleanupExpiredAccountsForTests();

        self::assertSame(['expiration', 'Deleted account 1 (test)', 'info'], \Lotgd\GameLog::$entries[0] ?? null);
        self::assertCount(2, \Lotgd\GameLog::$entries, 'the per-account line plus the run summary');
        self::assertSame('info', \Lotgd\GameLog::$entries[1][2] ?? null);
        self::assertContains('COMMIT', CoreDatabase::getDoctrineConnection()->queries);
    }

    /**
     * The run summary names the accounts that were actually deleted, not the
     * ones that were considered. An operator reading it is checking what is
     * gone.
     */
    public function testTheSummaryNamesOnlyTheAccountsThatWereDeleted(): void
    {
        Database::$mockResults = [[
            ['acctid' => 1, 'login' => 'foo', 'dragonkills' => 0, 'level' => 1],
            ['acctid' => 2, 'login' => 'bar', 'dragonkills' => 1, 'level' => 2],
        ]];
        // bar's cleanup fails, so bar is not deleted and must not be counted.
        \Lotgd\PlayerFunctions::$cleanupFailsFor = [2];

        ExpireChars::cleanupExpiredAccountsForTests();

        $summary = array_values(array_filter(
            \Lotgd\GameLog::$entries,
            static fn (array $entry): bool => str_contains($entry[1], 'accounts:')
        ));

        self::assertCount(1, $summary);
        self::assertStringContainsString('Deleted 1 accounts:', $summary[0][1]);
        self::assertStringContainsString('foo:dk0-lv1', $summary[0][1]);
        self::assertStringNotContainsString('bar:dk1-lv2', $summary[0][1]);
        self::assertSame('info', $summary[0][2]);
    }
}
