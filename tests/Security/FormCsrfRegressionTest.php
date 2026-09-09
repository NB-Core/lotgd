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
        self::assertTrue(
            Csrf::validatePostRequest(Csrf::SCOPE_CREATURE_EDITOR),
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
     * Every save branch that showForm() feeds validates before it writes.
     *
     * Source assertions, deliberately paired with the behavioural coverage in
     * tests/User: emission is proven above, and what remains is that the one
     * line exists at each write.
     */
    public function testEverySaveBranchValidates(): void
    {
        $expected = [
            'configuration.php' => 3,
            'titleedit.php' => 1,
            'prefs.php' => 1,
            'pages/user/user_save.php' => 1,
            'pages/user/user_savemodule.php' => 1,
            'pages/user/user_special.php' => 1,
        ];

        foreach ($expected as $file => $count) {
            self::assertSame(
                $count,
                substr_count($this->code($file), 'Forms::validateCsrf()'),
                $file . ' must validate before writing'
            );
        }
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
