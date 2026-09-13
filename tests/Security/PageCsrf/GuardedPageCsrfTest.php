<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security\PageCsrf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pages that guard an operation with a form token, asked by running them.
 *
 * Every other CSRF check in this suite asks the question of the source text:
 * that `Forms::isUnverifiedCoreOp($op, [...])` appears near the top of the
 * file, that its list has the right entries. That holds just as well when the
 * guard is unreachable, when the page exits before reaching it, or when the op
 * it names is not the op the page dispatches on -- none of which a substring
 * search can tell from success. These run the page and look at the SQL.
 *
 * A table rather than a class per page, for the reason the rest of this audit
 * gave: six near-identical classes differing in one string literal say less
 * than one table whose rows are the inventory. Adding a page is a row.
 *
 * Each row is exercised twice, and that pairing is the whole design. "No write
 * without a token" is satisfied by a page that never ran, and four earlier
 * versions of this harness were exactly that -- common.php has five exit paths
 * in front of every page body. So the first case asserts the write *does*
 * happen when the request is valid, and only then does the second assert it
 * does not when the token is missing. A row whose positive control breaks fails
 * loudly rather than going quietly green.
 *
 * Five of the sixteen pages carrying this guard are not in the table, and the
 * reason is the same for all of them, so it is worth stating rather than
 * leaving as an absence. bank.php, prefs.php and configuration.php perform
 * their operations *through the session or the settings object*: a deposit
 * calls Bank::deposit(), writes the new balance into `$session['user']`, and
 * lets the footer persist it with the same generic `UPDATE accounts` that every
 * request makes. There is no statement text that distinguishes a deposit from a
 * page view, so this mechanism cannot see it -- and a row that watched
 * `accounts` would be green in both directions, which is worse than no row. A
 * harness that reads the player's gold back instead was tried and abandoned
 * rather than shipped half-working: the session is already reset by the time a
 * shutdown handler can look at it. untranslated.php and translatortool.php need
 * a translator fixture (a language in `translatorlanguages` matching the
 * account's preference) that this harness does not yet build.
 *
 * bank.php is the one that matters most of those -- it moves every gold piece a
 * player owns -- so this is a gap and not a decision. Its arithmetic is covered
 * by tests/Bank, and its guard by tests/Security/BankCsrfRegressionTest, which
 * asks the source-text question this suite exists to replace.
 *
 * @see PageRunner for what it takes to make one of these pages execute at all.
 */
final class GuardedPageCsrfTest extends TestCase
{
    /**
     * One row per guarded operation: the page, the privilege it demands, the
     * request, and the statement that request issues.
     *
     * The deleting operation is chosen wherever a page has one. A delete needs
     * nothing but a query string, so the row states the whole request, and a
     * delete that escapes its guard is the worst of the outcomes available.
     *
     * The last column is the statement the operation issues, not the table it
     * touches: every request writes to `settings` and `accounts` whatever it is
     * doing, so a table name would be matched by the bootstrap's own traffic
     * and a row watching one would pass in both directions.
     *
     * @return array<string, array{0: string, 1: int, 2: array<string,mixed>, 3: array<string,mixed>, 4: string}>
     */
    public static function guardedOperations(): array
    {
        return [
            'masters.php deletes a master' => [
                'masters.php', SU_EDIT_CREATURES, ['op' => 'del', 'id' => '7'], [], 'DELETE FROM masters',
            ],
            'taunt.php deletes a taunt' => [
                'taunt.php', SU_EDIT_CREATURES, ['op' => 'del', 'tauntid' => '7'], [], 'DELETE FROM taunts',
            ],
            'deathmessages.php deletes a death message' => [
                'deathmessages.php', SU_EDIT_CREATURES, ['op' => 'del', 'deathmessageid' => '7'], [], 'DELETE FROM deathmessages',
            ],
            'titleedit.php deletes a title' => [
                'titleedit.php', SU_EDIT_USERS, ['op' => 'delete', 'id' => '7'], [], 'DELETE FROM titles',
            ],
            'bans.php lifts a ban' => [
                'bans.php', SU_EDIT_BANS, ['op' => 'delban', 'ipfilter' => '10.0.0.1', 'uniqueid' => 'abc'], [], 'DELETE FROM bans',
            ],
            'badword.php rewrites the nasty word list' => [
                'badword.php', SU_EDIT_COMMENTS, ['op' => 'remove'], [], 'INSERT INTO nastywords',
            ],
            // Not a superuser page: mail is every player's, and a crafted link
            // that deletes someone's mail needs no privilege at all to be worth
            // forging. The privilege column is 0 to say so rather than by
            // omission.
            'mail.php deletes a message' => [
                'mail.php', 0, ['op' => 'del', 'id' => '7'], [], 'DELETE FROM mail',
            ],
            'modules.php deactivates a module' => [
                'modules.php', SU_MANAGE_MODULES, ['op' => 'deactivate', 'module' => 'testmodule'], [], 'DELETE FROM modules',
            ],
            'user.php lifts a ban from the user editor' => [
                'user.php', SU_EDIT_USERS,
                ['op' => 'delban', 'ipfilter' => '10.0.0.1', 'uniqueid' => 'abc'], [], 'DELETE FROM bans',
            ],
            // The row that needed the signature column. donators.php credits
            // points with an UPDATE on `accounts`, and `accounts` is written on
            // every request by the bootstrap -- so a row watching the table
            // would have been green with and without a token alike.
            'donators.php credits donation points' => [
                'donators.php', SU_EDIT_DONATIONS,
                ['op' => 'add2', 'id' => '1', 'amt' => '5'], [], 'UPDATE accounts SET donation',
            ],
            // The one row whose payload is in the body rather than the query
            // string, so the body path is exercised at all.
            //
            // It does not, however, witness the guard's `$_POST = []`, and no
            // row here does. Measured across every one of them rather than
            // inferred from a couple: removing that line from each of the
            // eleven pages in turn leaves all 33 cases green, eleven times
            // over. Every dispatch in this table is `if ($op == ...)`, so
            // blanking $op has already shut the door; modules.php is the
            // nearest thing to an exception, since its POST buttons set $op
            // after the guard has run, but they only apply when $op is already
            // 'mass', which the guard has blanked too. So the body-blanking
            // here is defence in depth over a door that is already shut, and
            // this suite does not prove it. Said plainly rather than claimed by
            // a row that would not fail without it.
            'moderate.php deletes a comment' => [
                'moderate.php', SU_EDIT_COMMENTS, ['op' => 'commentdelete'],
                ['comment' => ['7' => '1'], 'delete' => 'Delete'], 'DELETE FROM commentary',
            ],
        ];
    }

    /**
     * The positive control for every row.
     *
     * This is the case that makes the other two mean something, so it is first
     * and it is not optional: if the harness cannot reach the operation, the
     * row is not testing the guard, it is testing the bootstrap.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $body
     */
    #[DataProvider('guardedOperations')]
    public function testAVerifiedRequestPerformsTheOperation(
        string $page,
        int $privilege,
        array $request,
        array $body,
        string $signature
    ): void {
        $outcome = PageRunner::withToken($page, $privilege, $request, $body);

        self::assertNotSame(
            [],
            $outcome->statementsContaining($signature),
            "$page never issued \"$signature\" even with a valid token, so the refusal case below would prove "
            . 'nothing. Statements seen: ' . implode(' | ', $outcome->statements)
        );
    }

    /**
     * And the same request, unverified, writes nothing.
     *
     * Asserted against the page's own table rather than against "no statements
     * at all": every request updates the online counter and the session row
     * whatever it is doing, so the latter is never true and a test written that
     * way would have to be loosened until it said nothing.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $body
     */
    #[DataProvider('guardedOperations')]
    public function testAnUnverifiedRequestPerformsNothing(
        string $page,
        int $privilege,
        array $request,
        array $body,
        string $signature
    ): void {
        $outcome = PageRunner::withoutToken($page, $privilege, $request, $body);

        self::assertSame(
            [],
            $outcome->statementsContaining($signature),
            "an unverified request issued \"$signature\" on $page: " . implode(' | ', $outcome->statements)
        );
    }

    /**
     * The refusal is reported as one.
     *
     * A separate claim from "nothing was written", and separately load-bearing:
     * the status code is how an operator's logs tell a blocked forgery from
     * somebody browsing the editor. Removing only `http_response_code(400)`
     * from a page leaves the case above green and fails this one.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $body
     */
    #[DataProvider('guardedOperations')]
    public function testAnUnverifiedRequestIsReportedAsABadRequest(
        string $page,
        int $privilege,
        array $request,
        array $body,
        string $signature
    ): void {
        $outcome = PageRunner::withoutToken($page, $privilege, $request, $body);

        self::assertSame(400, $outcome->status, "$page did not report its refusal");
    }
}
