<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * The three destructive operations that were reachable without a token.
 *
 * Each of these used to be triggerable by making a browser issue a request:
 * account deletion and character self-deletion were plain GET links, and
 * rawsql.php executed whatever a POST carried. `SameSite=Lax` does send the
 * session cookie on a top-level GET navigation, and the navigation allowlist
 * only narrowed the window rather than closing it -- see SECURITY.md on what
 * that allowlist does and does not cover.
 *
 * These are source assertions, which is the weaker kind of test: they prove the
 * guard is written, not that it fires. Two things compensate. Csrf itself is
 * covered behaviourally in CsrfTest, so what is left to check is that the call
 * sits in the path at all; and the ordering assertion below is a real one --
 * it compares positions, so moving the guard back where it was fails it.
 */
final class DestructiveOperationCsrfRegressionTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testDeletingAnAccountRequiresAPostedToken(): void
    {
        $source = $this->source('pages/user/user_del.php');

        self::assertStringContainsString(
            'Csrf::validatePostRequest(Csrf::SCOPE_USER_EDITOR)',
            $source
        );
        // Refusal must not fall through into the deletion.
        self::assertMatchesRegularExpression(
            '/if \(!Csrf::validatePostRequest\(Csrf::SCOPE_USER_EDITOR\)\) \{.*?return;\s*\}/s',
            $source
        );
    }

    public function testTheAccountDeletionTriggerIsAFormAndNotALink(): void
    {
        $source = $this->source('pages/user/user_.php');

        self::assertStringContainsString("action='user.php?op=del&userid={\$row['acctid']}' method='POST'", $source);
        self::assertStringContainsString('Csrf::hiddenField(Csrf::SCOPE_USER_EDITOR)', $source);
        // The bare anchor is what made a crafted URL enough.
        self::assertStringNotContainsString("<a href='user.php?op=del", $source);
    }

    /**
     * The bug the CSRF work uncovered, which is not itself a CSRF bug.
     *
     * charCleanup() removes an account's comments, output cache and clan
     * membership. It used to run *before* the check that only a megauser may
     * delete a superuser, so refusing the deletion still left the account
     * stripped -- the guard destroyed what it existed to protect. Positions,
     * not mere presence: swapping them back fails this.
     */
    public function testTheMegauserGuardRunsBeforeAnythingIsRemoved(): void
    {
        $source = $this->source('pages/user/user_del.php');

        $guard = strpos($source, 'SU_MEGAUSER');
        $cleanup = strpos($source, 'PlayerFunctions::charCleanup(');

        self::assertIsInt($guard, 'the megauser guard must still exist');
        self::assertIsInt($cleanup, 'the cleanup call must still exist');
        self::assertLessThan(
            $cleanup,
            $guard,
            'the megauser check must run before charCleanup() removes anything'
        );
    }

    public function testSelfDeletionRequiresAPostedTokenAndIgnoresTheRequestedAccount(): void
    {
        $source = $this->source('prefs.php');

        self::assertStringContainsString('Csrf::validatePostRequest(Csrf::SCOPE_SELF_DELETE)', $source);
        self::assertStringContainsString('Csrf::hiddenField(Csrf::SCOPE_SELF_DELETE)', $source);

        // The account came from the query string, and charCleanup() never
        // compares its argument to the session -- so the allowlist was the only
        // thing keeping a player from deleting somebody else.
        self::assertStringNotContainsString("\$userid = (int)Http::get('userid');", $source);
        self::assertStringContainsString("\$userid = (int) (\$session['user']['acctid'] ?? 0);", $source);
        self::assertStringNotContainsString("prefs.php?op=suicide&userid=", $source);

        // And it must no longer be interpolated into the delete.
        self::assertStringNotContainsString('WHERE acctid=$userid', $source);
        self::assertStringContainsString('WHERE acctid = :acctid', $source);
    }

    /**
     * The self-delete button's texts are escaped for where they land.
     *
     * Both come from Translator::translateInline(), which reads the
     * translations table -- written under SU_IS_TRANSLATOR, so they are not
     * constants. They went raw into a single-quoted attribute and into a
     * double-quoted confirm() argument, so an apostrophe closed the attribute
     * and a double quote closed the JS string. On a page every player opens.
     *
     * The first assertions are the control: they show the break-out is real,
     * so the ones after them mean something.
     */
    public function testTheSelfDeleteConfirmCannotBreakOut(): void
    {
        $hostile = 'x");alert(document.cookie);//';
        $apostrophe = "Charakter l'oeschen";

        // What the old code produced.
        self::assertStringContainsString(
            '");alert(',
            'onClick=\'return confirm("' . $hostile . '");\'',
            'the raw form really did break out of the confirm() argument'
        );
        self::assertSame(2, substr_count("value='" . $apostrophe . "'", "'") - 1);

        // What it produces now.
        $confJs = json_encode($hostile, JSON_HEX_APOS | JSON_HEX_QUOT);
        self::assertIsString($confJs);
        self::assertStringNotContainsString('");alert(', $confJs);
        self::assertStringNotContainsString('"', substr($confJs, 1, -1), 'no bare quote may survive');
        self::assertStringNotContainsString("'", htmlspecialchars($apostrophe, ENT_QUOTES, 'UTF-8'));

        // And that the page actually uses it.
        $source = $this->source('prefs.php');
        self::assertStringContainsString('JSON_HEX_APOS | JSON_HEX_QUOT', $source);
        self::assertStringContainsString('confirm($confJs)', $source);
        self::assertStringNotContainsString('confirm(\\"$conf\\")', $source);
        self::assertStringNotContainsString("value='\$deltext'", $source);
    }

    public function testRawSqlAndRawPhpRefuseToRunWithoutAToken(): void
    {
        $source = $this->source('rawsql.php');

        self::assertSame(
            2,
            substr_count($source, 'Csrf::validatePostRequest(Csrf::SCOPE_RAW_SQL)'),
            'both the SQL and the PHP branch must be guarded'
        );
        self::assertSame(
            2,
            substr_count($source, 'Csrf::hiddenField(Csrf::SCOPE_RAW_SQL)'),
            'both forms must carry the token'
        );

        // The guard has to precede execution, not merely exist in the file.
        $guard = strpos($source, 'Csrf::validatePostRequest(Csrf::SCOPE_RAW_SQL)');
        self::assertIsInt($guard);
        self::assertLessThan(strpos($source, 'Database::query($sql, false)'), $guard);
        self::assertLessThan(strpos($source, 'eval($php)'), $guard);
    }

    /**
     * Each destructive operation gets its own scope.
     *
     * rawsql.php executes arbitrary SQL and PHP; a token issued by a creature
     * editor must not open it. Scope separation is what makes that true, so the
     * constants are asserted to be distinct rather than assumed to be.
     */
    public function testTheNewScopesAreDistinct(): void
    {
        $scopes = [
            Csrf::SCOPE_USER_EDITOR,
            Csrf::SCOPE_SELF_DELETE,
            Csrf::SCOPE_RAW_SQL,
            Csrf::SCOPE_CREATURE_EDITOR,
            Csrf::SCOPE_MOTD_EDIT,
            Csrf::SCOPE_ASYNC,
        ];

        self::assertSame($scopes, array_values(array_unique($scopes)));
    }

    /**
     * A token is scoped, so one minted elsewhere must not open rawsql.php.
     * This one is behavioural: it exercises the class rather than the file.
     */
    public function testACreatureEditorTokenDoesNotOpenRawSql(): void
    {
        $GLOBALS['session'] = [];
        $borrowed = Csrf::token(Csrf::SCOPE_CREATURE_EDITOR);

        self::assertFalse(Csrf::matches(Csrf::SCOPE_RAW_SQL, $borrowed));
        self::assertTrue(Csrf::matches(Csrf::SCOPE_CREATURE_EDITOR, $borrowed));
    }
}
