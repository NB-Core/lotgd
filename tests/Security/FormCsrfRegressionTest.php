<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Forms;
use Lotgd\Output;
use Lotgd\Security\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * The token showForm() emits, and the collision that decided its field name.
 *
 * Emission is automatic because a caller who has to remember it will not:
 * that is how seven editors came to write without one. Validation stays a
 * single explicit line in each save branch, because it has to run where the
 * page decides to write, which is not somewhere showForm() ever reaches.
 */
final class FormCsrfRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        global $forms_output;
        $forms_output = '';
        Output::setInstance(null);
        $GLOBALS['session'] = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_NAME'] = '/configuration.php';
    }

    /**
     * A file's source with comments removed.
     *
     * The counting assertions below would otherwise be satisfied by a comment
     * that merely names the function -- which is not a hypothetical: the note
     * added to mounts.php explaining why it strips mentions `Csrf::stripFrom()`
     * by name. A test that a comment can satisfy is not a test.
     */
    private function code(string $relativePath): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function render(bool $nosave = false, bool $tabbed = false): string
    {
        if ($tabbed) {
            Forms::showFormTabbed(['flag' => 'Flag,checkbox'], ['flag' => 1], $nosave);
        } else {
            Forms::showForm(['flag' => 'Flag,checkbox'], ['flag' => 1], $nosave);
        }

        return Output::getInstance()->getRawOutput();
    }

    public function testASubmittableFormCarriesTheToken(): void
    {
        $html = $this->render();

        self::assertStringContainsString("name='" . Csrf::FORM_FIELD . "'", $html);
    }

    public function testTabbedFormsCarryItToo(): void
    {
        $html = $this->render(false, true);

        self::assertStringContainsString("name='" . Csrf::FORM_FIELD . "'", $html);
    }

    /**
     * A form with no submit button cannot post, so it needs no token -- and
     * issuing one would hand the scope's token to every page that merely
     * displays settings (home.php, about_setup.php, charrestore.php).
     */
    public function testAReadOnlyFormGetsNoToken(): void
    {
        $html = $this->render(true);

        self::assertStringNotContainsString(Csrf::FORM_FIELD, $html);
        self::assertNull(Csrf::peek(Forms::csrfScope()), 'no token may be minted for a view');
    }

    public function testTheEmittedTokenIsWhatValidateCsrfAccepts(): void
    {
        $html = $this->render();

        self::assertSame(1, preg_match(
            "/name='" . Csrf::FORM_FIELD . "' value='([a-f0-9]{64})'/",
            $html,
            $m
        ), 'the rendered field must carry a token');

        $_POST[Csrf::FORM_FIELD] = $m[1];
        self::assertTrue(Forms::validateCsrf());
    }

    public function testValidateCsrfRejectsAGetAndAWrongToken(): void
    {
        $this->render();

        $_POST[Csrf::FORM_FIELD] = str_repeat('0', 64);
        self::assertFalse(Forms::validateCsrf(), 'a wrong token must not pass');

        $_POST[Csrf::FORM_FIELD] = Csrf::peek(Forms::csrfScope());
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertFalse(Forms::validateCsrf(), 'a GET must not pass');
    }

    /**
     * The scope follows the entry script, so a token minted by the title
     * editor does not open the game configuration.
     */
    public function testTheScopeFollowsTheEntryScript(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/configuration.php';
        $configuration = Forms::csrfScope();
        $_SERVER['SCRIPT_NAME'] = '/titleedit.php';
        $titleedit = Forms::csrfScope();

        self::assertNotSame($configuration, $titleedit);

        $_SERVER['SCRIPT_NAME'] = '/../../etc/pass wd.php';
        self::assertMatchesRegularExpression('/^form:[A-Za-z0-9._-]+$/', Forms::csrfScope());
    }

    /**
     * The reason the field is not named `csrf_token`.
     *
     * creatures.php, mounts.php and companions.php wrap module_objpref_edit()
     * -- which reaches Forms::showForm() -- inside a form that already carries
     * an editor token. Two inputs of the same name in one form is legal HTML,
     * and PHP keeps the last: the page's token would be silently replaced and
     * the guard added for that editor would start refusing every save.
     *
     * The first assertion is the control. It shows the clobbering is real
     * rather than theoretical, so the second one means something.
     */
    public function testAPageTokenAndAFormTokenDoNotClobberEachOther(): void
    {
        $pageToken = str_repeat('b', 64);
        $formToken = str_repeat('c', 64);

        // What a shared name would have produced.
        parse_str('csrf_token=' . $pageToken . '&csrf_token=' . $formToken, $shared);
        self::assertSame($formToken, $shared['csrf_token'], 'the page token is lost -- this is the bug avoided');

        // What separate names produce.
        parse_str(Csrf::FIELD . '=' . $pageToken . '&' . Csrf::FORM_FIELD . '=' . $formToken, $separate);
        self::assertSame($pageToken, $separate[Csrf::FIELD]);
        self::assertSame($formToken, $separate[Csrf::FORM_FIELD]);

        Csrf::seed(Csrf::SCOPE_CREATURE_EDITOR, $pageToken);
        $_POST = $separate;
        self::assertFalse(
            Forms::isUnverifiedPost(Csrf::SCOPE_CREATURE_EDITOR),
            'the editor guard must survive a showForm() nested in its form'
        );
    }

    /**
     * Both tokens must be stripped before a POST body reaches a writer, or
     * they are persisted as data -- configuration.php writes every posted key
     * into the settings table.
     */
    public function testStripFromRemovesBothTokenFields(): void
    {
        $stripped = Csrf::stripFrom([
            Csrf::FIELD => 'x',
            Csrf::FORM_FIELD => 'y',
            'motditems' => '5',
        ]);

        self::assertSame(['motditems' => '5'], $stripped);
    }

    /**
     * Every page that changes state guards its own operations, exactly once.
     *
     * One shape, not one per branch: a per-branch check is one somebody can
     * forget, which is how a dozen editors came to write with no token at all.
     *
     * The list is the point twice over. It is what "everywhere" means, so a new
     * state-changing page without a guard fails here; and each page's own list
     * of operations is what keeps the core out of modules' way — see
     * testAnOperationTheCoreDoesNotKnowIsLeftAlone().
     */
    public function testEveryStateChangingPageGuardsItsOwnOperations(): void
    {
        $pages = [
            'badword.php', 'bans.php', 'configuration.php', 'deathmessages.php',
            'donators.php', 'mail.php', 'masters.php', 'moderate.php', 'modules.php',
            'prefs.php', 'taunt.php', 'titleedit.php', 'translatortool.php',
            'untranslated.php', 'user.php',
        ];

        foreach ($pages as $page) {
            $code = $this->code($page);
            self::assertSame(
                1,
                substr_count($code, 'Forms::isUnverifiedCoreOp($op, ['),
                $page . ' must guard its own operations exactly once'
            );
            // It has to clear both: a delete keys off $op with its id in the
            // query string, so emptying the body alone would not stop it.
            self::assertMatchesRegularExpression(
                '/isUnverifiedCoreOp\(.*?\) \{.*?\$op = \x27\x27;.*?\$_POST = \[\];.*?\}/s',
                $code,
                $page . ' must clear both $op and the body'
            );
        }
    }

    /**
     * Pages whose writes key off a posted field rather than $op guard at the
     * write instead. Same question, asked where the core decides to change
     * something.
     */
    public function testBodyDrivenWritesGuardAtTheWrite(): void
    {
        foreach ([
            'viewpetition.php',
            'pages/clan/clan_motd.php',
            'pages/clan/clan_membership.php',
            'pages/clan/detail.php',
        ] as $page) {
            self::assertStringContainsString(
                'Forms::isUnverifiedPost()',
                $this->code($page),
                $page . ' must guard its write'
            );
        }
    }

    /**
     * The guarantee for modules, which is why the op list exists.
     *
     * Modules render into prefs.php, clan.php, mail.php and moderate.php
     * through hooks and may post forms of their own. An old module cannot carry
     * a token it has never heard of, and `runmodule.php` is not guarded at all,
     * so an operation the core page does not implement must pass through
     * untouched — otherwise this release silently breaks working modules.
     */
    public function testAnOperationTheCoreDoesNotKnowIsLeftAlone(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_NAME'] = '/prefs.php';
        $_POST = ['mymodulefield' => 'x'];
        $GLOBALS['session'] = [];

        // No token anywhere, which is exactly an old module's POST.
        self::assertFalse(
            Forms::isUnverifiedCoreOp('mymoduleop', ['save', 'suicide']),
            "a module's own operation must not be refused"
        );

        // And the core's own operation is still guarded in the same call.
        self::assertTrue(
            Forms::isUnverifiedCoreOp('suicide', ['save', 'suicide']),
            "the core's operation must still be refused"
        );
    }

    /**
     * runmodule.php is the module entry point and carries no guard, so a
     * module's own pages are untouched by any of this.
     */
    public function testRunmoduleIsNotGuarded(): void
    {
        $code = $this->code('runmodule.php');

        self::assertStringNotContainsString('isUnverifiedCoreOp', $code);
        self::assertStringNotContainsString('isUnverifiedPost', $code);
    }

    /**
     * And nobody keeps a private copy of the check.
     */
    public function testNoPageValidatesOnItsOwn(): void
    {
        foreach (['configuration.php', 'prefs.php', 'titleedit.php', 'user.php'] as $page) {
            self::assertStringNotContainsString(
                'Forms::validateCsrf()',
                $this->code($page),
                $page . ' must use the entry guard rather than its own check'
            );
        }
    }

    /**
     * The dead `Http::set($op, "")` must not come back.
     *
     * The house idiom is `$op = Http::get('op'); Http::set('op', ''); …;`
     * (Modules.php), and configuration.php had it with the *variable* where
     * the key belongs. After `$op = ""` that passes the empty string as the
     * key, and Http::set() only writes a key that is already present, so the
     * call did nothing at all: `$_GET['op']` stayed `"save"`.
     *
     * Removed rather than corrected. Nothing on the page reads `Http::get('op')`
     * again -- the local `$op = ""` is what makes it fall through to the editor
     * -- so making the call work would switch on behaviour that has never
     * existed, for no benefit. Two of the four were copied in by the refusal
     * branches added here, which is how it surfaced.
     */
    public function testTheDeadOpResetIsGone(): void
    {
        $code = $this->code('configuration.php');

        self::assertStringNotContainsString('Http::set($op', $code);
        self::assertStringContainsString('$op = "";', $code, 'the local reset is what actually works');
    }

    /**
     * A page that hands its whole POST body onward strips first.
     */
    public function testTheBulkWritersStrip(): void
    {
        // mounts.php is here because it was writing the token as a mount
        // module preference before this change -- the form carries the editor
        // token, and the loop persisted every posted key.
        $files = [
            'configuration.php' => 3,
            'prefs.php' => 1,
            'pages/user/user_save.php' => 1,
            'mounts.php' => 1,
            'companions.php' => 1,
        ];

        foreach ($files as $file => $count) {
            $source = $this->code($file);
            self::assertSame(
                $count,
                substr_count($source, 'Csrf::stripFrom('),
                $file . ' must strip the token before writing the body'
            );
            self::assertStringNotContainsString('$post = httpallpost();', $source);
        }
    }
}
