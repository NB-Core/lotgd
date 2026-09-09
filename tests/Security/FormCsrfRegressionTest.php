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

    /**
     * The pages that carry an entry guard. This list is what "everywhere"
     * means: a new state-changing page without a guard fails the test below.
     *
     * @return list<string>
     */
    private function guardedPages(): array
    {
        return [
            'badword.php', 'bans.php', 'configuration.php', 'deathmessages.php',
            'donators.php', 'mail.php', 'masters.php', 'moderate.php', 'modules.php',
            'prefs.php', 'taunt.php', 'titleedit.php', 'translatortool.php',
            'untranslated.php', 'user.php',
        ];
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
            Forms::isUnverifiedRequest(Csrf::SCOPE_CREATURE_EDITOR),
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
        foreach ($this->guardedPages() as $page) {
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
            // The preference save has no $op of its own: `op=save` and a plain
            // view fall into the same branch and everything is written out of
            // the posted body, so listing an op could not have guarded it.
            'prefs.php',
            // op=audit is the review view; the write under it is subop=undelete.
            'moderate.php',
            // op=list is the browsing view; the write under it is mode=save.
            'untranslated.php',
        ] as $page) {
            self::assertStringContainsString(
                'Forms::isUnverifiedRequest()',
                $this->code($page),
                $page . ' must guard its write'
            );
        }
    }

    /**
     * The preference save, which had fallen through the sweep entirely.
     *
     * prefs.php has no `op=save` branch: `op=save` and an ordinary page view
     * enter the same `else`, and every write below runs out of the posted body.
     * The entry guard listed `''` for that reason, which got it backwards in
     * both directions at once -- `save` was not listed, so the save itself was
     * left with no CSRF check at all after the inline one was removed, while
     * `''` matched every ordinary GET view of the page and answered it 400.
     *
     * So the question is asked where the body is taken. The write is already
     * gated on `count($post)`, so an emptied body is the page's own way of
     * saying "nothing was posted".
     */
    public function testThePreferenceSaveIsGuardedAtTheBodyItWrites(): void
    {
        $code = $this->code('prefs.php');

        self::assertStringContainsString(
            '$post = Forms::isUnverifiedRequest() ? [] : Csrf::stripFrom(Http::allPost());',
            $code,
            'the body a tokenless POST carries must never reach the write'
        );
        // The write really is gated on the body being non-empty, which is what
        // makes emptying it sufficient.
        self::assertStringContainsString('if (count($post) == 0) {', $code);

        self::assertSame(1, preg_match('/isUnverifiedCoreOp\(\$op, \[([^\]]*)\]/', $code, $m));
        // Listing the empty op answered 400 on every ordinary page view.
        self::assertStringNotContainsString("''", $m[1], 'a page view is not a state change');
        // And the two email buttons are real POST forms, so they carry a token.
        foreach (['forcechangeemail', 'cancelemail'] as $emailOp) {
            self::assertStringContainsString(
                "prefs.php?op=" . $emailOp . "' method='POST'>\" . Forms::csrfField()",
                $code,
                $emailOp . ' posts, so its form must carry the token'
            );
        }
    }

    /**
     * The clan membership writes read their ids from the body, not the URL.
     *
     * This guard was written to look right and fired for none of the operations
     * it existed to protect. It asked the body for `setrank`/`remove`, but the
     * two destructive buttons carried those in the *query string* and posted
     * only a token, so `postIsset()` was false and the guard was skipped for
     * exactly them. Founder demotion and member removal stayed triggerable by a
     * forged top-level GET -- the hole this whole PR is about. Only the rank
     * `<select>`, the one path that genuinely posts, was ever covered.
     *
     * Clearing `$_POST` could not have saved it either: the ids were read with
     * `Http::get()`, so the values survived the guard that was meant to erase
     * them. Both halves had to move into the body.
     */
    public function testTheClanMembershipIdsComeOnlyFromTheBody(): void
    {
        $code = $this->code('pages/clan/clan_membership.php');

        // The reads. A GET-carried id is what made the guard cosmetic.
        foreach (['setrank', 'whoacctid', 'remove'] as $field) {
            self::assertStringContainsString(
                "\$" . ($field === 'setrank' ? 'setrank' : ($field === 'remove' ? 'remove' : 'whoacctid'))
                    . " = (int) Http::post('" . $field . "');",
                $code,
                $field . ' must be read from the body'
            );
            self::assertStringNotContainsString(
                "Http::get('" . $field . "')",
                $code,
                $field . ' must not fall back to the query string'
            );
        }

        // The triggers. Every destructive one posts its ids as hidden fields,
        // so the target URL carries no id at all.
        self::assertStringNotContainsString('op=membership&setrank=', $code);
        self::assertStringNotContainsString('op=membership&remove=', $code);
        self::assertStringNotContainsString('op=membership&whoacctid=', $code);

        // And the guard still stands in front of them.
        self::assertMatchesRegularExpression(
            '/Forms::isUnverifiedRequest\(\).*?postIsset\(\x27setrank\x27\).*?postIsset\(\x27remove\x27\)/s',
            $code,
            'the write must still be guarded'
        );
    }

    /**
     * A page scope must never be passed as an explicit scope argument.
     *
     * The two paths through the guard read *different fields*. A scopeless call
     * goes to `validateCsrf()`, which reads `FORM_FIELD` -- what
     * `Forms::csrfField()` renders. An explicit scope goes straight to
     * `Csrf::validatePostRequest($scope)`, which defaults to `FIELD` -- what a
     * narrower `postButton()` scope renders.
     *
     * `pages/clan/applicant_new.php` passed `'form:clan.php'`, which names the
     * right scope and reads the wrong field, so no clan application could ever
     * pass. The scope matching is what makes it look correct at a glance.
     */
    public function testAPageScopePassedExplicitlyReadsTheWrongField(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/clan.php';
        $scope = Forms::csrfScope();
        Csrf::seed($scope, str_repeat('a', 64));

        // What clanform() renders is the page token, in FORM_FIELD.
        $_POST = [Csrf::FORM_FIELD => str_repeat('a', 64)];

        // The control: the explicit-scope path reads FIELD and finds nothing.
        self::assertTrue(
            Forms::isUnverifiedRequest($scope),
            'the explicit-scope path reads csrf_token -- this is the bug'
        );
        // The scopeless path reads FORM_FIELD and accepts it.
        self::assertFalse(
            Forms::isUnverifiedRequest(),
            'the page path must accept the token the page rendered'
        );

        // So no caller may name a page scope. A real narrower scope is fine --
        // its button renders FIELD to match.
        $callers = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2)));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            if (preg_match_all('/isUnverifiedRequest\(\s*[\x27"]([^\x27"]+)[\x27"]/', (string) file_get_contents($path), $m)) {
                foreach ($m[1] as $literal) {
                    $callers[] = basename($path) . ": '" . $literal . "'";
                }
            }
        }

        self::assertSame(
            [],
            $callers,
            "a scope passed as a string literal reads the wrong field:\n" . implode("\n", $callers)
        );
    }

    /**
     * The shared commentary form carries the page token.
     *
     * `Commentary::talkForm()` renders the comment box on every page that has
     * one, and it emitted no token at all -- so the guard on viewpetition.php,
     * which keys off `insertcommentary` in the body, refused every legitimate
     * petition response. The form posts back to the page it was rendered on, so
     * the page scope is the right one for it to carry.
     */
    public function testTheCommentaryFormCarriesTheToken(): void
    {
        $code = $this->code('src/Lotgd/Commentary.php');

        // Immediately inside the form tag, not merely somewhere in the file.
        $formTag = '$output->outputNotl("<form action=\\"$req\\" method=\'POST\' autocomplete=\'false\'>", true);';
        $position = strpos($code, $formTag);
        self::assertIsInt($position, 'the commentary form tag must still exist');
        self::assertStringStartsWith(
            '$output->rawOutput(Forms::csrfField());',
            ltrim(substr($code, $position + strlen($formTag))),
            'the token must be the first thing inside the form'
        );
        // And it is the page token, which is what the guard reading the body
        // on viewpetition.php accepts.
        self::assertStringContainsString('Forms::isUnverifiedRequest()', $this->code('viewpetition.php'));
    }

    /**
     * No guarded operation may also be a link somebody can click.
     *
     * This is the shape of the mistake the guard itself created. Once a listed
     * op required a verified POST -- which is the whole point, and what 15cb88c
     * restored -- every listed op that was actually a *view* started answering
     * 400. Five did: `titleedit.php?op=add` and `?op=reset`,
     * `configuration.php?op=testsmtp`, `moderate.php?op=audit`,
     * `untranslated.php?op=list`, plus `donators.php?op=add1`, which is reached
     * by a nav link from two other pages and which nothing in the manual pass
     * caught -- this check did.
     *
     * A `Nav::add()` with link text is a clickable GET, so an op that is both
     * listed and linked is either a broken view or a destructive link. Neither
     * may exist: the view comes off the list, the destructive link becomes a
     * `Forms::postButton()`. The allowlist-only form, `Nav::add('', $url)`, is
     * how a POST target stays navigable and is deliberately not matched.
     */
    public function testNoGuardedOperationIsAlsoAClickableLink(): void
    {
        $root = dirname(__DIR__, 2);
        $lists = [];
        foreach ($this->guardedPages() as $page) {
            self::assertSame(1, preg_match(
                '/isUnverifiedCoreOp\(\$op, \[([^\]]*)\]/',
                $this->code($page),
                $m
            ), $page . ' must carry an entry guard');
            preg_match_all("/'([^']*)'/", $m[1], $ops);
            $lists[$page] = array_filter($ops[1], static fn (string $op): bool => $op !== '');
        }

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

            $code = (string) file_get_contents($path);
            // Nav::add("text", "page.php?op=…") -- a non-empty first argument.
            $pattern = '/(?:Nav|Navigation)::add\(\s*(["\'])((?:(?!\1).)+)\1\s*,\s*(["\'])([^"\']*?)\3/';
            if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $url = $match[4];
                foreach ($lists as $page => $ops) {
                    foreach ($ops as $op) {
                        $needle = $page . '?op=' . $op;
                        if (!str_starts_with($url, $needle)) {
                            continue;
                        }
                        // Only a whole op, so `op=add` does not match `op=add1`.
                        if (strlen($url) !== strlen($needle) && $url[strlen($needle)] !== '&') {
                            continue;
                        }
                        $found[] = substr($path, strlen($root) + 1)
                            . ': Nav::add("' . $match[2] . '", "' . $url . '")';
                    }
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($found)),
            "a guarded op must not also be a clickable GET link:\n"
                . implode("\n", array_unique($found))
        );
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
