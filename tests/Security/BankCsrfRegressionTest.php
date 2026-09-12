<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Forms;
use Lotgd\Security\Csrf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bank.php had no CSRF guard at all.
 *
 * Every other core page that changes state carries
 * `Forms::isUnverifiedCoreOp()` at its entry -- eighteen of them by the time
 * this was noticed -- and the page that moves a player's entire fortune was not
 * among them. A forged POST to `bank.php?op=transfer3` carrying `to` and
 * `amount` moved the victim's gold to whoever sent it.
 *
 * SameSite=Lax keeps an ordinary cross-site POST from carrying the session
 * cookie, which is why this was not trivially exploitable from the open web.
 * It is not the whole story: modules render into core pages through hooks and
 * post forms of their own, and an operator running SameSite=None has no such
 * protection. Reported by Codex on #1528.
 *
 * The guard sits once at the entry rather than per branch, because the next
 * branch somebody adds is the one a per-branch check gets forgotten in.
 */
final class BankCsrfRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['session'] = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // The page scope follows the running script, and bank.php is the page
        // whose token these forms carry.
        $_SERVER['SCRIPT_NAME'] = '/bank.php';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['SCRIPT_NAME']);
    }

    /**
     * Every operation that moves money refuses a request without a token.
     */
    #[DataProvider('moneyMovingOperationProvider')]
    public function testAMoneyMovingOperationIsRefusedWithoutAToken(string $op): void
    {
        self::assertTrue(
            Forms::isUnverifiedCoreOp($op, self::guardedOperations()),
            $op . ' must not be reachable without a token'
        );
    }

    /**
     * And accepts one carrying the page's token.
     */
    #[DataProvider('moneyMovingOperationProvider')]
    public function testTheSameOperationIsAcceptedWithTheToken(string $op): void
    {
        $_POST[Csrf::FORM_FIELD] = Csrf::token(Forms::csrfScope());

        self::assertFalse(
            Forms::isUnverifiedCoreOp($op, self::guardedOperations()),
            $op . ' must go through once the form token is present'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function moneyMovingOperationProvider(): array
    {
        return [
            'completing a transfer' => ['transfer3'],
            'previewing a transfer' => ['transfer2'],
            'finishing a deposit' => ['depositfinish'],
            'finishing a withdrawal or loan' => ['withdrawfinish'],
        ];
    }

    /**
     * The list is a boundary rather than bookkeeping: an op the core does not
     * implement belongs to a module, and guarding it would break modules that
     * post into this page through a hook.
     */
    public function testAnOperationTheCoreDoesNotOwnPassesThrough(): void
    {
        self::assertFalse(
            Forms::isUnverifiedCoreOp('somemodulething', self::guardedOperations()),
            'a module operation must not be swallowed by the core guard'
        );
    }

    /**
     * The views are not guarded, because they change nothing and a player
     * arrives at them by navigation rather than by a form.
     */
    #[DataProvider('viewOperationProvider')]
    public function testAViewIsNotGuarded(string $op): void
    {
        self::assertFalse(Forms::isUnverifiedCoreOp($op, self::guardedOperations()));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function viewOperationProvider(): array
    {
        return [
            'the bank lobby' => [''],
            'the transfer form' => ['transfer'],
            'the deposit form' => ['deposit'],
            'the withdrawal form' => ['withdraw'],
            'the loan form' => ['borrow'],
        ];
    }

    /**
     * The page itself carries the guard and the list, and every form that posts
     * to a guarded operation emits a token.
     *
     * Checked against the source rather than by executing the page, because
     * bank.php is a top-level script that needs a session, a database and a
     * rendered header before it reaches any of this. The behaviour above is
     * what the executing cases cover; this one guards the wiring.
     */
    public function testThePageCarriesTheGuardAndEveryFormCarriesAToken(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bank.php');

        self::assertStringContainsString(
            "Forms::isUnverifiedCoreOp(\$op, ['transfer2', 'transfer3', 'depositfinish', 'withdrawfinish'])",
            $source,
            'the entry guard, with every money-moving operation on the list'
        );

        preg_match_all(
            "/<form action='bank\\.php\\?op=([a-z0-9]+)' method='POST'>\"( \\. Forms::csrfField\\(\\))?/i",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        self::assertNotEmpty($matches, 'the forms should be findable');

        foreach ($matches as $match) {
            if (!in_array($match[1], self::guardedOperations(), true)) {
                continue;
            }

            self::assertNotEmpty(
                $match[2] ?? '',
                'the form posting to ' . $match[1] . ' must emit a token, or the guard locks players out'
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function guardedOperations(): array
    {
        return ['transfer2', 'transfer3', 'depositfinish', 'withdrawfinish'];
    }
}
