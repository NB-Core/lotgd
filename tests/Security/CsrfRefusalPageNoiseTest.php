<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * A refusal is logged; browsing is not.
 *
 * Moving the log line into the guard turned a pure predicate into one with a
 * side effect, and that is the risk the change had to answer: five call sites
 * asked the guard speculatively, before knowing whether anything had been
 * written at all, and would have filed a security event for an ordinary page
 * view. A security log that reports ordinary browsing is worse than no
 * security log, because the one real entry is then indistinguishable from the
 * thousand that mean nothing.
 *
 * One page, run four ways, and each cell of the table is a control on the
 * others. Without the "no op" rows, "a refusal is recorded" would hold just as
 * well for a guard that records everything; without the token rows, "an
 * ordinary view records nothing" would hold for a guard that records nothing.
 *
 * bans.php because the harness can reach its guard and its write: the clan
 * pages need a clan rank the harness does not model and prefs.php redirects
 * before the branch in question. The ordering those five sites now depend on
 * is pinned by source in FormCsrfRegressionTest, which says so rather than
 * claiming a behavioural proof this file does not have.
 */
final class CsrfRefusalPageNoiseTest extends TestCase
{
    /** @param array<string,string> $get */
    private static function bans(array $get, bool $withToken): PageOutcome
    {
        $run = $withToken ? PageRunner::withToken(...) : PageRunner::withoutToken(...);

        return $run('bans.php', SU_EDIT_BANS, $get, [], []);
    }

    /** @return array<string,string> */
    private static function lift(): array
    {
        return ['op' => 'delban', 'ipfilter' => '10.0.0.1', 'uniqueid' => 'abc'];
    }

    private static function securityRows(PageOutcome $outcome): int
    {
        return count($outcome->statementsContaining('INSERT INTO gamelog'));
    }

    public function testARefusedOperationIsRecordedAndDoesNotHappen(): void
    {
        $refused = self::bans(self::lift(), withToken: false);

        self::assertSame(1, self::securityRows($refused), 'the refusal is recorded');
        self::assertSame([], $refused->statementsContaining('DELETE FROM bans'), 'and nothing was deleted');
        self::assertSame(400, $refused->status);
    }

    /**
     * The control on the row above: with a token the same request goes through,
     * and records nothing. A guard that logged unconditionally would fail here.
     */
    public function testTheSameOperationWithATokenGoesThroughAndRecordsNothing(): void
    {
        $accepted = self::bans(self::lift(), withToken: true);

        self::assertCount(1, $accepted->statementsContaining('DELETE FROM bans'), 'control: it really is the write');
        self::assertSame(0, self::securityRows($accepted));
        self::assertNotSame(400, $accepted->status);
    }

    /**
     * And the case that made the five call sites need rearranging: a request
     * that asked for no operation at all.
     *
     * Without a token as well as with one, because that is what a visitor
     * opening the page in a fresh tab looks like -- no form has been submitted,
     * so there is nothing for a token to be attached to.
     */
    public function testOpeningTheGuardedPageRecordsNothingEitherWay(): void
    {
        foreach ([true, false] as $withToken) {
            $view = self::bans([], $withToken);

            self::assertSame(
                0,
                self::securityRows($view),
                'looking at a guarded page is not a refused state change (token: '
                    . var_export($withToken, true) . ')'
            );
        }
    }
}
