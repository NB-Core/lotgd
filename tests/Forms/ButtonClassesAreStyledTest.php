<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

use Lotgd\Tests\Support\StyleSheets;
use PHPUnit\Framework\TestCase;

/**
 * Every class handed to a button helper must be styleable on a button.
 *
 * This suite has spent a long time deleting tests that read source text, so
 * one that does exactly that owes an explanation.
 *
 * The difference is what is being asserted. Those tests grepped for *behaviour*
 * -- that a query is parameterised, that a guard is called -- which is
 * observable by running the code, so reading the file was the weaker way to ask
 * a question that had a better one. This asserts a relationship between two
 * artefacts, PHP call sites and CSS files, and there is no moment at runtime
 * when "this class is styled" becomes observable: PHP emits a class attribute
 * and never learns what a browser does with it. Reading both sides is not a
 * substitute for executing something. It is the only way to ask at all.
 *
 * The question earns a test because it has now been answered wrongly three
 * times, each under a new name:
 *
 *   - `motd`       (#1540) -- defined in every theme, but only as `a.motd`, so
 *                  it styled the anchors and did nothing for the buttons
 *                  wearing it. Delete, Mark Unread and Report to Admin rendered
 *                  as bare browser chrome.
 *   - `linkbutton` (#1541) -- defined in no stylesheet at all, at 17 call sites
 *                  across the administration pages.
 *   - `motd-del` and `user-del` -- the same, missed by #1541 because that sweep
 *                  searched for the literal string `linkbutton` instead of for
 *                  the property. `user-del` is the button that deletes a
 *                  player's account.
 *
 * Searching for a name finds the instances of that name. This asks the question
 * the name was standing in for, so the next `foo-del` fails here instead of
 * turning up two releases later.
 */
final class ButtonClassesAreStyledTest extends TestCase
{
    /**
     * Roughly how many call sites the scanner should be finding.
     *
     * The positive control, and the reason it is here rather than implied: a
     * scanner that matches nothing reports no bad classes, and a test asserting
     * only "no bad classes" passes loudest exactly when it has stopped working.
     * The number is a floor rather than an equality so that adding a button
     * does not fail an unrelated build; it is well under the real count, so a
     * parser that breaks still trips it.
     */
    private const AT_LEAST_THIS_MANY_CALL_SITES = 20;

    /**
     * Roughly how many of those call sites should yield a class.
     *
     * Counting call sites is not enough on its own, and this was measured
     * rather than reasoned: a splitter that stops at the first `)` still finds
     * all 28 call sites and resolves 23 of the 27 classes, because the four it
     * truncates look to the scanner like calls that simply take the default.
     * Sites alone stayed green through that. This does not.
     *
     * Below the real count so that removing one button does not fail an
     * unrelated build; if buttons are genuinely removed, lower it on purpose.
     */
    private const AT_LEAST_THIS_MANY_RESOLVED_CLASSES = 25;

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every class literal handed to postButton() or formActionButton().
     *
     * Forms.php itself is excluded: the helpers are declared there, and their
     * `string $class = 'button'` defaults would otherwise be read as call sites.
     * The defaults are covered instead by defaultClasses() below, so nothing is
     * lost by the exclusion.
     *
     * Returns the classes, the number of call sites seen, and the arguments it
     * could not read. The count is what proves the scan happened at all; the
     * unreadable ones are reported rather than dropped, because a class this
     * cannot resolve is a class it cannot judge, and silently judging fewer
     * things is how a guard goes quiet.
     *
     * @return array{classes: array<string, list<string>>, sites: int, unresolved: list<string>}
     */
    private static function classesAtCallSites(): array
    {
        $root = self::repositoryRoot();
        $found = [];
        $unresolved = [];
        $count = 0;

        foreach (self::phpFiles($root) as $file) {
            $source = (string) file_get_contents($file);
            $offset = 0;

            $helper = '/\b(?:postButton|formActionButton)\s*\(/';

            while (preg_match($helper, $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $open = $match[0][1] + strlen($match[0][0]) - 1;
                $offset = $open + 1;
                $arguments = self::argumentsAt($source, $open);
                if ($arguments === null) {
                    continue;
                }

                $count++;
                // Fewer than four arguments means the call takes the default
                // class, which defaultClasses() covers.
                if (count($arguments) < 4) {
                    continue;
                }

                $site = substr($file, strlen($root) + 1);
                $class = self::classLiteral($arguments[3]);
                if ($class === null) {
                    $unresolved[] = $site . ': ' . trim($arguments[3]);
                    continue;
                }

                $found[$class][] = $site;
            }
        }

        return ['classes' => $found, 'sites' => $count, 'unresolved' => $unresolved];
    }

    /**
     * The class an argument passes, or null when reading it cannot say.
     *
     * Both PHP quote styles are literals, and treating only one of them as a
     * literal is how this check would have gone quiet: a call passing
     * "new-del" would have been ignored while still counting towards the
     * call-site floor below, so the floor would have stayed green as well.
     *
     * A double-quoted string that interpolates is not a literal -- "$prefix-del"
     * is a variable wearing quotes -- and neither is a bare variable. Those
     * return null and are reported, not skipped: this cannot judge a class it
     * cannot read, and a guard that quietly judges fewer things each release is
     * the failure mode the whole file exists to avoid.
     */
    private static function classLiteral(string $argument): ?string
    {
        $argument = trim($argument);

        if (preg_match("/^'([^'\\\\]*)'$/", $argument, $literal) === 1) {
            return $literal[1];
        }

        if (preg_match('/^"([^"\\\\$]*)"$/', $argument, $literal) === 1) {
            return $literal[1];
        }

        return null;
    }

    /**
     * The class defaults the helpers themselves declare.
     *
     * Parameter defaults only, and that is now the whole of what is here to
     * read. `actionBar()` used to hand a literal 'button mail-nav__link' to
     * every entry; it composes that class from the container's name instead,
     * so no literal remains and no pattern over this file could find one. The
     * classes it composes are covered by ActionBarClassesAreStyledTest, which
     * renders a row and reads what came out -- a better question than this
     * one, asked the only way it can be asked.
     *
     * @return list<string>
     */
    private static function defaultClasses(): array
    {
        $source = (string) file_get_contents(self::repositoryRoot() . '/src/Lotgd/Forms.php');

        // Both quote styles, for the same reason classLiteral() reads both.
        preg_match_all('/\$class\s*=\s*([\'"])([^\'"$]+)\1/', $source, $parameters);

        return array_values(array_unique(array_filter($parameters[2])));
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current): bool {
                    $name = $current->getFilename();
                    if ($current->isDir()) {
                        return !in_array($name, ['vendor', 'tests', '.git', 'node_modules'], true);
                    }

                    return str_ends_with($name, '.php');
                }
            )
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, 'src/Lotgd/Forms.php')) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    /**
     * Split one call's argument list, respecting nesting and quotes.
     *
     * A naive split on commas gets this wrong wherever an argument is itself a
     * call or an array -- which is most of them -- and a naive match to the
     * first `)` stops inside the first nested one. Both mistakes make the
     * scanner quietly see fewer call sites, which is the failure the floor
     * above exists to catch.
     *
     * @return list<string>|null
     */
    private static function argumentsAt(string $source, int $open): ?array
    {
        $depth = 0;
        $parts = [];
        $current = '';
        $quote = null;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $current .= $char . ($source[$i + 1] ?? '');
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                $current .= $char;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
                if ($depth > 1) {
                    $current .= $char;
                }
                continue;
            }

            if ($char === ')' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    $parts[] = $current;

                    return $parts;
                }
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depth === 1) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        return null;
    }

    /**
     * The scanner is finding call sites at all.
     *
     * Asserted before anything else, because every other assertion here is of
     * the form "nothing bad was found", and an empty scan satisfies all of them.
     */
    public function testTheScannerFindsTheCallSites(): void
    {
        self::assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_CALL_SITES,
            self::classesAtCallSites()['sites'],
            'the call-site scanner found almost nothing, so the assertions below prove nothing'
        );
    }

    /**
     * The scan is resolving classes, and the two sites this PR fixed are in it.
     *
     * The floor's companion: an argument splitter that truncates makes a call
     * look like one that takes the default class, so it disappears from every
     * judgement below while still counting as a site. The two named sites are
     * the delete buttons this change was about -- if either stops being found,
     * the scan has drifted away from the thing it was built to watch.
     */
    public function testTheScannerResolvesTheClassesItFinds(): void
    {
        $classes = self::classesAtCallSites()['classes'];

        $resolved = 0;
        $sites = [];
        foreach ($classes as $files) {
            $resolved += count($files);
            $sites = array_merge($sites, $files);
        }

        self::assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_RESOLVED_CLASSES,
            $resolved,
            'the scanner is finding call sites but reading far fewer classes than it should'
        );

        self::assertContains('pages/user/user_.php', $sites, "the account-delete button's class went unread");
        self::assertContains('src/Lotgd/Motd.php', $sites, "the MoTD delete button's class went unread");
    }

    /**
     * Every class argument in the tree could actually be read.
     *
     * The second half of the same guard: the floor above counts call sites,
     * this one counts the ones whose class it could resolve. Without it a call
     * passing a variable, or an interpolated string, is scanned, counted and
     * never judged.
     */
    public function testEveryClassArgumentCanBeAnalysed(): void
    {
        $unresolved = self::classesAtCallSites()['unresolved'];

        self::assertSame(
            [],
            $unresolved,
            "These class arguments could not be read, so their class was never checked:\n"
                . implode("\n", $unresolved)
                . "\n\nPass a plain string literal, or teach classLiteral() to read this form."
        );
    }

    /**
     * The literal reader handles both quote styles, and refuses the rest.
     *
     * Tested directly because no call site in the tree passes a double-quoted
     * class today: the hole this closes is one no fixture would have shown.
     */
    public function testTheLiteralReaderAcceptsBothQuoteStyles(): void
    {
        self::assertSame('new-del', self::classLiteral("'new-del'"));
        self::assertSame('new-del', self::classLiteral('"new-del"'));
        self::assertSame('button mail-nav__link', self::classLiteral("  'button mail-nav__link'  "));

        self::assertNull(self::classLiteral('$class'), 'a variable class cannot be read');
        self::assertNull(self::classLiteral('"$prefix-del"'), 'an interpolated string is not a literal');
        self::assertNull(self::classLiteral("'a' . \$b"), 'a concatenation is not a literal');
    }

    /**
     * The reachability rule reads the rightmost compound, not any occurrence.
     *
     * `.foo a` mentions the class and styles the anchor inside it. Counting
     * that would let a class no button can ever wear pass as styled, which is
     * #1540's defect with one extra step.
     */
    public function testTheReachabilityRuleReadsTheRightmostCompound(): void
    {
        self::assertTrue(StyleSheets::isButtonReachable('.foo', 'foo'));
        self::assertTrue(StyleSheets::isButtonReachable('button.foo', 'foo'));
        self::assertTrue(StyleSheets::isButtonReachable('.bar .foo', 'foo'), 'the class is still the rightmost part');
        self::assertTrue(StyleSheets::isButtonReachable('.foo:hover', 'foo'));

        self::assertFalse(StyleSheets::isButtonReachable('a.foo', 'foo'), 'names another element');
        self::assertFalse(StyleSheets::isButtonReachable('.foo a', 'foo'), 'styles the anchor inside, not the button');
        self::assertFalse(StyleSheets::isButtonReachable('.foo > span', 'foo'));
        self::assertFalse(StyleSheets::isButtonReachable('.foobar', 'foo'), 'a different class that starts the same');
    }

    public function testTheStyleSheetsAreFound(): void
    {
        $sheets = StyleSheets::paths();

        self::assertGreaterThan(5, count($sheets), 'no stylesheets found, so no class can be judged');
    }

    /**
     * The check itself rejects the class that started all this.
     *
     * A guard on the guard. `motd` is defined in nine stylesheets and is still
     * wrong on a button, so if this ever passes, selectorsFor() has been
     * loosened into something that would have called #1540's defect healthy --
     * and every other assertion in this file would go quiet at the same moment.
     */
    public function testTheCheckRejectsAnAnchorOnlyClass(): void
    {
        $motd = StyleSheets::selectorsFor('motd');

        self::assertNotEmpty($motd['all'], 'motd should still be defined in the themes');
        self::assertEmpty(
            $motd['reachable'],
            'motd is defined only as a.motd; a button wearing it is unstyled, which is what #1540 was about'
        );
    }

    public function testTheCheckAcceptsAnUnqualifiedClass(): void
    {
        $button = StyleSheets::selectorsFor('button');

        self::assertNotEmpty($button['reachable'], '.button should be defined unqualified in the themes');
    }

    /**
     * The actual question.
     */
    public function testEveryButtonClassIsStyleableOnAButton(): void
    {
        $unstyled = [];

        foreach (self::classesAtCallSites()['classes'] as $classAttribute => $files) {
            foreach (preg_split('/\s+/', trim($classAttribute)) ?: [] as $class) {
                if ($class === '') {
                    continue;
                }

                $missing = StyleSheets::themesMissing($class);
                if ($missing === []) {
                    continue;
                }

                $found = StyleSheets::selectorsFor($class);
                $unstyled[] = sprintf(
                    "  '%s' (%s) -- %s; unstyled in %d of %d themes: %s",
                    $class,
                    implode(', ', array_unique($files)),
                    $found['all'] === []
                        ? 'defined in no stylesheet at all'
                        : sprintf('defined only as %s, which cannot match a button', $found['all'][0]),
                    count($missing),
                    count(StyleSheets::themes()),
                    implode(', ', $missing)
                );
            }
        }

        self::assertSame(
            [],
            $unstyled,
            "These classes reach a button that no stylesheet can style:\n" . implode("\n", $unstyled)
                . "\n\nUse 'button', which every bundled theme defines unqualified."
        );
    }

    /**
     * The defaults the helpers declare, which no call site names.
     */
    public function testEveryDefaultClassIsStyleableOnAButton(): void
    {
        $defaults = self::defaultClasses();

        self::assertNotEmpty($defaults, 'no default class literals found in Forms.php');

        // Named rather than merely counted, because a pattern that has stopped
        // matching reads exactly like a file with nothing to match.
        self::assertContains('button', $defaults, "the helpers' own default class went unread");

        $unstyled = [];
        foreach ($defaults as $classAttribute) {
            foreach (preg_split('/\s+/', trim($classAttribute)) ?: [] as $class) {
                if ($class !== '' && StyleSheets::themesMissing($class) !== []) {
                    $unstyled[] = $class . ' (unstyled in '
                        . implode(', ', StyleSheets::themesMissing($class)) . ')';
                }
            }
        }

        self::assertSame([], $unstyled, 'default classes no theme can style: ' . implode(', ', $unstyled));
    }
}
