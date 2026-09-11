<?php

declare(strict_types=1);

namespace Lotgd\Tests\User;

use Lotgd\MySQL\Database as CoreDatabase;
use Lotgd\Security\Csrf;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * pages/user/user_del.php deletes a character, so what matters is every path
 * on which it must not.
 *
 * Split out of CharCleanupFailurePreventsDeletionTest, whose first case was
 * about ExpireChars rather than this page and now lives with the rest of that
 * subject in ExpireChars/CleanupExpiredAccountsTest.
 *
 * Each case include()s the page at test scope, so each needs its own process.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class UserDelGuardTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
        CoreDatabase::resetDoctrineConnection();
        $connection = CoreDatabase::getDoctrineConnection();
        $connection->queries = [];
        $connection->executeStatementResults = [];
    }


    /**
     * Stubs shared by the user_del.php cases below.
     */
    private function prepareUserDel(bool $withToken): object
    {
        if (! class_exists('Lotgd\\PlayerFunctions', false)) {
            eval('namespace Lotgd; class PlayerFunctions { public static function charCleanup(int $id, int $type): bool { $GLOBALS[\'cleanup_called\'] = true; return false; } }');
        }
        if (! class_exists('Lotgd\\AddNews', false)) {
            eval('namespace Lotgd; class AddNews { public static function add(string $m, string $n, bool $b = true): void {} }');
        }
        if (! function_exists('debuglog')) {
            eval('function debuglog(string $m): void {}');
        }

        global $session, $userid, $output;
        $session = ['user' => ['superuser' => 0]];
        $userid = 1;
        $GLOBALS['cleanup_called'] = false;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        if ($withToken) {
            Csrf::seed(Csrf::SCOPE_USER_EDITOR, str_repeat('a', 64));
            $_POST[Csrf::FIELD] = str_repeat('a', 64);
        }

        $output = new class {
            public array $log = [];
            public function output(string $m): void
            {
                $this->log[] = $m;
            }
        };

        Database::$mockResults = [
            [["name" => "Tester", "superuser" => 0]],
        ];

        return $output;
    }

    public function testUserDelAbortsOnCleanupFailure(): void
    {
        // The token is supplied deliberately. Without it the CSRF guard added
        // in front of this path returns first, and the test would pass while
        // proving nothing about the cleanup abort it was written for.
        // The included file runs in this scope, so the globals it reads
        // have to be bound here, not only in the helper.
        global $session, $userid, $output;
        $this->prepareUserDel(true);

        include __DIR__ . '/../../pages/user/user_del.php';

        $queries = CoreDatabase::getDoctrineConnection()->queries;
        $this->assertCount(1, $queries, 'only the lookup may run');
        $this->assertStringContainsString('SELECT name, superuser FROM', $queries[0]);
        $this->assertTrue($GLOBALS['cleanup_called'], 'the abort must come from cleanup, not from the token check');
        $this->assertSame([], CoreDatabase::getDoctrineConnection()->executeStatements);
    }

    /**
     * The megauser guard must refuse *before* anything is removed.
     *
     * charCleanup() strips an account's comments, output cache and clan
     * membership. It used to run before this check, so refusing to delete a
     * superuser still left that account gutted -- the guard destroyed exactly
     * what it existed to protect, and the refusal message made it look like
     * nothing had happened.
     */
    public function testDeletingASuperuserAsANonMegauserRemovesNothing(): void
    {
        global $session, $userid, $output;
        $this->prepareUserDel(true);

        // A superuser target, and an admin who is not a megauser.
        $session['user']['superuser'] = 0;
        Database::$mockResults = [
            [["name" => "Admin", "superuser" => 1]],
        ];

        include __DIR__ . '/../../pages/user/user_del.php';

        $this->assertFalse($GLOBALS['cleanup_called'], 'nothing may be stripped before the guard passes');
        $this->assertSame([], CoreDatabase::getDoctrineConnection()->executeStatements);
        $this->assertNotSame([], $output->log, 'the admin must be told why');
    }

    /**
     * Deleting an account used to be a plain GET link, so an admin following a
     * crafted URL removed it. Nothing may happen without the token -- not even
     * the lookup, and above all not charCleanup(), which strips the account
     * before the row is gone.
     */
    public function testUserDelDoesNothingWithoutAToken(): void
    {
        // The included file runs in this scope, so the globals it reads
        // have to be bound here, not only in the helper.
        global $session, $userid, $output;
        $this->prepareUserDel(false);

        include __DIR__ . '/../../pages/user/user_del.php';

        // The refusal is now recorded in the security channel, so the log insert is
        // the one statement a refused deletion is allowed to make. Nothing may reach
        // the account itself.
        foreach (CoreDatabase::getDoctrineConnection()->queries as $query) {
            $this->assertStringNotContainsString('accounts', $query);
        }
        foreach (CoreDatabase::getDoctrineConnection()->executeStatements as $statement) {
            $this->assertStringContainsString('gamelog', is_array($statement) ? ($statement['sql'] ?? '') : $statement);
        }
        $this->assertFalse($GLOBALS['cleanup_called'], 'charCleanup must not run');
        $this->assertSame(400, http_response_code());
        $this->assertNotSame([], $output->log);
    }
}
