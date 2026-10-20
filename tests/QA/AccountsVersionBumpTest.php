<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\ReactiveCoreInventory;
use PHPUnit\Framework\TestCase;

/**
 * Every write to the game state of an account moves accounts.version on, so
 * a guarded action of that player notices it instead of booking over it
 * (docs/ReactiveCore.md, Phase 3). Bookkeeping -- logging in and out, laston,
 * the stored navigation, password rehashes -- and deletes do not.
 *
 * A new `UPDATE accounts` in the core fails here until it either moves the
 * version on or is listed below with the reason it does not.
 */
final class AccountsVersionBumpTest extends TestCase
{
    private const BUMP = 'version = version + 1';

    /**
     * File, a text that identifies the statement, and why it does not move the version.
     */
    private const EXEMPT = [
        ['login.php', 'password', 'password rehash at login'],
        ['login.php', 'loggedin', 'login and logout bookkeeping'],
        ['login.php', 'allowednavs', 'stored navigation'],
        ['superuser.php', 'laston', 'keep-alive'],
        ['pages/user/user_special.php', 'SET lasthit', 'resets the new-day marker the page end writes as bookkeeping'],
        ['pages/user/user_saveban.php', 'loggedin = 0', 'logging banned players out'],
        ['pages/bans/case_saveban.php', 'loggedin = 0', 'logging banned players out'],
        ['src/Lotgd/Async/Handler/Timeout.php', 'laston', 'keep-alive'],
        ['src/Lotgd/ExpireChars.php', 'sentnotice', 'expiry notice bookkeeping'],
        ['src/Lotgd/ForcedNavigation.php', 'page_revision', 'the page revision claim'],
        ['src/Lotgd/Accounts.php', '', "saveUser()'s own write, which sets the version itself"],
        ['src/Lotgd/Action.php', 'FOR UPDATE', 'the account lock, not a write'],
    ];

    public function testEveryGameplayWriteToAnAccountMovesItsVersionOn(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];

        foreach ((new ReactiveCoreInventory())->collect($root) as $finding) {
            if ($finding['category'] !== ReactiveCoreInventory::CATEGORY_ACCOUNTS_WRITE || !str_starts_with($finding['detail'], 'UPDATE')) {
                continue;
            }

            $statement = $this->statementAround($root . '/' . $finding['file'], $finding['line']);
            if (str_contains($statement, self::BUMP) || $this->exempt($finding['file'], $statement)) {
                continue;
            }

            $missing[] = $finding['file'] . ':' . $finding['line'];
        }

        self::assertSame([], $missing, "These writes to accounts do not move the version on:\n" . implode("\n", $missing));
    }

    public function testTheCheckCatchesAWriteWithoutTheBump(): void
    {
        $finding = (new ReactiveCoreInventory())->scanSource(
            "<?php\n\$c->executeStatement('UPDATE ' . Database::prefix('accounts') . ' SET gold = 0 WHERE acctid = 1');\n",
            'example.php'
        );

        self::assertSame(ReactiveCoreInventory::CATEGORY_ACCOUNTS_WRITE, $finding[0]['category']);
        self::assertFalse($this->exempt('example.php', 'SET gold = 0'));
    }

    /**
     * The lines a statement can span around where the scanner found it;
     * editors assemble the SET list a few lines ahead.
     */
    private function statementAround(string $file, int $line): string
    {
        $lines = (array) file($file);

        return implode('', array_slice($lines, max(0, $line - 8), 16));
    }

    private function exempt(string $file, string $statement): bool
    {
        foreach (self::EXEMPT as [$exemptFile, $needle]) {
            if ($file === $exemptFile && ($needle === '' || str_contains($statement, $needle))) {
                return true;
            }
        }

        return false;
    }
}
