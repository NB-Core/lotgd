<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Forms;
use Lotgd\Security\Csrf;
use Lotgd\Security\Escape;
use PHPUnit\Framework\TestCase;

/**
 * The two helpers that replaced the hand-written recipes.
 *
 * Before this, the tree held four different ways to ask "are you sure?" —
 * `json_encode` with two flags, `addslashes(htmlentities())`, a raw
 * interpolation, and a local `mountEditorActionForm()` that rebuilt the whole
 * form. Three of them were wrong. These tests pin the one that replaced them,
 * behaviourally: they render output and inspect it, rather than asserting that
 * a file contains a particular spelling.
 */
final class EscapeAndPostButtonTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['session'] = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_NAME'] = '/taunt.php';
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function hostileStrings(): iterable
    {
        yield 'apostrophe closes a single-quoted attribute' => ["Charakter l'oeschen"];
        yield 'double quote closes the confirm argument' => ['Sag "ja"'];
        yield 'a full break-out' => ['x");alert(document.cookie);//'];
        yield 'a closing script tag' => ['</script><script>alert(1)</script>'];
        yield 'a backslash' => ['back\\slash'];
        yield 'a newline' => ["two\nlines"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileStrings')]
    public function testJsProducesALiteralNothingCanEscapeFrom(string $hostile): void
    {
        $literal = Escape::js($hostile);

        // It quotes itself, so a caller adding quotes is doing it wrong.
        self::assertStringStartsWith('"', $literal);
        self::assertStringEndsWith('"', $literal);

        // Nothing inside can end the literal, the attribute, or a script block.
        $inside = substr($literal, 1, -1);
        foreach (['"', "'", '<', '>', '&', "\n", "\r"] as $terminator) {
            self::assertStringNotContainsString($terminator, $inside, 'must not survive encoding');
        }

        // And it still means the same thing.
        self::assertSame($hostile, json_decode($literal, true));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileStrings')]
    public function testHtmlProducesAnAttributeNothingCanEscapeFrom(string $hostile): void
    {
        $escaped = Escape::html($hostile);

        foreach (['"', "'", '<', '>'] as $terminator) {
            self::assertStringNotContainsString($terminator, $escaped);
        }
        self::assertSame($hostile, html_entity_decode($escaped, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * The control: this is what the old shape did with the same input.
     */
    public function testTheOldShapeReallyDidBreakOut(): void
    {
        $hostile = 'x");alert(document.cookie);//';

        $old = "onclick='return confirm(\"" . $hostile . "\");'";
        self::assertStringContainsString('");alert(document.cookie);', $old);

        $new = Escape::confirmAttribute($hostile);
        self::assertStringNotContainsString('");alert(document.cookie);', $new);
    }

    public function testConfirmAttributeHangsWhereItIsAsked(): void
    {
        self::assertStringStartsWith(" onclick='return confirm(", Escape::confirmAttribute('x'));
        self::assertStringStartsWith(" onsubmit='return confirm(", Escape::confirmAttribute('x', 'onsubmit'));
    }

    /**
     * The button is a POST, carries a token, and escapes everything it renders.
     */
    public function testPostButtonIsAPostCarryingAToken(): void
    {
        $html = Forms::postButton('taunt.php?op=del&tauntid=7', 'Del', 'Sure?');

        self::assertStringContainsString("method='POST'", $html);
        self::assertStringContainsString("name='" . Csrf::FORM_FIELD . "'", $html);
        self::assertStringContainsString('confirm(', $html);
        self::assertStringNotContainsString('<a href', $html, 'a delete must not be a link');

        // The token it renders is the one the page will accept back.
        self::assertSame(1, preg_match("/value='([a-f0-9]{64})'/", $html, $m));
        $_POST[Csrf::FORM_FIELD] = $m[1];
        self::assertFalse(Forms::isUnverifiedRequest());
    }

    public function testPostButtonEscapesLabelUrlAndConfirmation(): void
    {
        $html = Forms::postButton(
            "x.php?a=1&b='2",
            "Del'ete",
            'Sure "now"?',
            "cls'x"
        );

        // The tag must not be broken by any of the four.
        self::assertSame(1, substr_count($html, "<form "), 'exactly one form tag');
        self::assertStringNotContainsString("a=1&b='2", $html);
        self::assertStringNotContainsString("Del'ete", $html);
        self::assertStringNotContainsString("cls'x", $html);
        self::assertStringNotContainsString('confirm("Sure "now"?")', $html);
    }

    public function testPostButtonCanCarryANarrowerScopeAndHiddenFields(): void
    {
        $html = Forms::postButton(
            'motd.php?op=del&id=3',
            'Del',
            null,
            'motd-del',
            Csrf::SCOPE_MOTD_EDIT,
            ['op' => 'del', 'id' => 7]
        );

        self::assertStringContainsString("name='" . Csrf::FIELD . "'", $html);
        self::assertStringNotContainsString(Csrf::FORM_FIELD, $html, 'the narrower scope replaces the page token');
        self::assertStringContainsString("name='op' value='del'", $html);
        self::assertStringContainsString("name='id' value='7'", $html);
    }

    /**
     * A GET is unverified too, and this test exists because I got it backwards.
     *
     * The first version of the guard returned false for a non-POST, on the
     * reasoning that "a GET is never a state change" — and the first version of
     * this test asserted exactly that, so it pinned the bug rather than the
     * behaviour. It was wrong twice over: a GET *was* a state change on these
     * pages (following a crafted `user.php?op=del&userid=N` deleted the
     * account, which is what the previous release was about), and the check it
     * replaced, `Csrf::validatePostRequest()`, refused a GET for precisely that
     * reason. Returning false handed that hole straight back, at every site the
     * sweep touched.
     *
     * Ordinary browsing keeps working because of *where* the question is asked,
     * not because of the method: an operation the core does not own is never
     * asked about, and a page whose write keys off a posted field asks only
     * when that field is present, which a GET never has.
     */
    public function testAGetIsUnverified(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertTrue(Forms::isUnverifiedRequest(), 'a GET can never be verified');
        self::assertTrue(
            Forms::isUnverifiedCoreOp('del', ['del']),
            'a GET asking for a core state change must be refused'
        );

        // But an operation the core does not implement is still none of its
        // business, whatever the method -- that is the module boundary.
        self::assertFalse(Forms::isUnverifiedCoreOp('mymoduleop', ['del']));
    }

    /**
     * The control, spelled out: the check this replaced refused a GET, so the
     * replacement has to as well. Same inputs, same answer.
     */
    public function testItMatchesTheCheckItReplaced(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            $_POST = [];

            self::assertSame(
                !Csrf::validatePostRequest(Csrf::SCOPE_USER_EDITOR),
                Forms::isUnverifiedRequest(Csrf::SCOPE_USER_EDITOR),
                $method . ': the guard must agree with the check it replaced'
            );
        }
    }

    public function testTheGuardCatchesAPostWithNoTokenAndAWrongOne(): void
    {
        self::assertTrue(Forms::isUnverifiedRequest(), 'no token at all');

        Csrf::seed(Forms::csrfScope(), str_repeat('a', 64));
        $_POST[Csrf::FORM_FIELD] = str_repeat('b', 64);
        self::assertTrue(Forms::isUnverifiedRequest(), 'a wrong token');

        $_POST[Csrf::FORM_FIELD] = str_repeat('a', 64);
        self::assertFalse(Forms::isUnverifiedRequest(), 'the right one passes');
    }

    /**
     * A token minted on one page does not open another.
     */
    public function testTheGuardIsPerPage(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/taunt.php';
        Csrf::seed(Forms::csrfScope(), str_repeat('a', 64));
        $_POST[Csrf::FORM_FIELD] = str_repeat('a', 64);
        self::assertFalse(Forms::isUnverifiedRequest());

        $_SERVER['SCRIPT_NAME'] = '/rawsql.php';
        self::assertTrue(Forms::isUnverifiedRequest(), 'the taunt token must not open rawsql.php');
    }

    /**
     * Nothing in the tree may build one of these by hand again.
     */
    public function testNoHandWrittenConfirmSurvives(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            if (str_contains($path, 'src/Lotgd/Security/Escape.php') || str_contains($path, 'src/Lotgd/Forms.php')) {
                continue;
            }

            $code = (string) file_get_contents($path);
            // A confirm() whose argument is not produced by the shared helpers.
            if (preg_match('/confirm\((?!\s*\)|" \. Escape::js|\$)/', $code)) {
                foreach (explode("\n", $code) as $line) {
                    if (str_contains($line, 'confirm(')
                        && !str_contains($line, 'Escape::js')
                        && !str_contains($line, 'confirmAttribute')
                        && !str_contains($line, 'postButton')
                        && !str_contains($line, '//')
                    ) {
                        $found[] = substr($path, strlen($root) + 1) . ': ' . trim($line);
                    }
                }
            }
        }

        self::assertSame([], $found, "confirm() must go through Escape or Forms:\n" . implode("\n", $found));
    }
}
