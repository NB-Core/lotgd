<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * The MoTD editor's state-changing operations.
 *
 * `op=del` was a plain `<a href>`: following a crafted link while logged in as
 * an admin removed the entry. The navigation allowlist narrowed that — the bare
 * URL is registered by Nav::add() and cleared by the next page view — but
 * SameSite=Lax does send the session cookie on a top-level GET navigation, so
 * an admin sitting on the MoTD page was one click away from it. `op=save` and
 * `op=savenew` wrote with no token at all.
 */
final class MotdEditingCsrfRegressionTest extends TestCase
{
    public function testEditingUsesItsOwnScopeAndNotTheVoteToken(): void
    {
        self::assertNotSame(
            Csrf::SCOPE_MOTD_VOTE,
            Csrf::SCOPE_MOTD_EDIT,
            'the vote token is rendered for every logged-in player who sees a poll'
        );

        $GLOBALS['session'] = [];
        $vote = Csrf::token(Csrf::SCOPE_MOTD_VOTE);

        self::assertFalse(
            Csrf::matches(Csrf::SCOPE_MOTD_EDIT, $vote),
            'a vote token must not satisfy an editing operation'
        );

        $GLOBALS['session'] = [];
    }

    public function testDeleteIsAPostFormCarryingTheEditingToken(): void
    {
        $source = $this->source('src/Lotgd/Motd.php');

        // Every such button in the tree renders through one helper now, so what
        // this asserts is that the call is made with the editing scope -- the
        // POST method, the token field and the confirmation are the helper's
        // business, covered behaviourally in EscapeAndPostButtonTest.
        self::assertStringContainsString(
            'Forms::postButton("motd.php?op=del&id=$id", $del, $conf, \'motd-del\', Csrf::SCOPE_MOTD_EDIT)',
            $source
        );

        // The shape that was the problem: a delete reachable by following a link.
        self::assertStringNotContainsString("<a href='motd.php?op=del&id=\$id'", $source);
    }

    /**
     * Edit stays a link on purpose — it only renders a form and changes
     * nothing — so a blanket "no motd.php links" assertion would be wrong.
     */
    public function testEditRemainsAPlainLink(): void
    {
        self::assertStringContainsString(
            "<a href='motd.php?op=\$editop&id=\$id'>",
            $this->source('src/Lotgd/Motd.php')
        );
    }

    public function testEveryWritingOperationValidatesTheToken(): void
    {
        $source = $this->source('motd.php');

        // del, save and savenew: three writes, and the vote path already had one.
        self::assertSame(
            2,
            substr_count($source, 'Forms::isUnverifiedRequest(Csrf::SCOPE_MOTD_EDIT)'),
            'both the save/savenew branch and the del branch must validate'
        );
        self::assertStringContainsString('Forms::isUnverifiedRequest(Csrf::SCOPE_MOTD_VOTE)', $source);
    }

    /**
     * POST-only matters independently of the token here: it is what stops the
     * operation from being reachable by a URL at all.
     */
    public function testWritingOperationsRequirePost(): void
    {
        $source = $this->source('motd.php');

        self::assertStringNotContainsString(
            'Csrf::validatePost(Csrf::SCOPE_MOTD_EDIT)',
            $source,
            'editing must use validatePostRequest, which also enforces the method'
        );
    }

    public function testBothEditorFormsCarryTheToken(): void
    {
        $source = $this->source('src/Lotgd/Motd.php');

        // motdForm (op=save) and motdPollForm (op=savenew) render the field
        // directly; the delete button passes the same scope to the shared
        // helper. All three must name SCOPE_MOTD_EDIT rather than the page
        // scope, which motd.php also issues to every player who sees a poll.
        self::assertSame(2, substr_count($source, 'Csrf::hiddenField(Csrf::SCOPE_MOTD_EDIT)'));
        self::assertSame(1, substr_count($source, 'Csrf::SCOPE_MOTD_EDIT)' . "\n"), 'the delete button passes the scope');
        self::assertSame(3, substr_count($source, 'Csrf::SCOPE_MOTD_EDIT'));
    }

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
