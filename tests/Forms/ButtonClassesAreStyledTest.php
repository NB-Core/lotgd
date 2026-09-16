<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

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
     * Returns both the classes and the number of call sites seen, because the
     * count is what proves the scan happened at all and a by-reference
     * out-parameter for it would be the uglier half of the same answer.
     *
     * @return array{classes: array<string, list<string>>, sites: int}
     */
    private static function classesAtCallSites(): array
    {
        $root = self::repositoryRoot();
        $found = [];
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

                $class = trim($arguments[3]);
                if (preg_match("/^'([^']*)'$/", $class, $literal) === 1) {
                    $found[$literal[1]][] = substr($file, strlen($root) + 1);
                }
                // A variable class cannot be resolved by reading the file. None
                // exist outside Forms.php today; if one appears it is silently
                // uncovered, which is why the call-site floor above is asserted
                // separately from the classes themselves.
            }
        }

        return ['classes' => $found, 'sites' => $count];
    }

    /**
     * The class defaults the helpers themselves declare.
     *
     * `Forms::actionBar()` hands 'button mail-nav__link' to postButton() for
     * every entry that does not override it, so it is a real class in the
     * rendered page even though no call site names it.
     *
     * @return list<string>
     */
    private static function defaultClasses(): array
    {
        $source = (string) file_get_contents(self::repositoryRoot() . '/src/Lotgd/Forms.php');
        preg_match_all("/\\\$class\s*=\s*'([^']+)'|:\s*'((?:button|mail-nav)[^']*)'/", $source, $matches);

        $classes = array_merge($matches[1], $matches[2]);

        return array_values(array_unique(array_filter($classes)));
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
     * Selectors defining `.$class` that a `<button>` could actually match.
     *
     * "Defined somewhere" is not the question. `a.motd` defines the class in
     * every theme shipped with the game and cannot match a button, which is the
     * whole of what went wrong in #1540 -- a test that only asked whether the
     * class existed would have called that row healthy.
     *
     * @return array{reachable: list<string>, all: list<string>}
     */
    private static function selectorsFor(string $class): array
    {
        $pattern = '/(?:^|[\s,>+~(])((?:[A-Za-z][\w-]*)?(?::[\w-]+)*)\.'
            . preg_quote($class, '/') . '(?![\w-])/m';

        $reachable = [];
        $all = [];

        foreach (self::styleSheets() as $sheet) {
            $text = (string) file_get_contents($sheet);
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $selector = trim($match[0]);
                $all[] = $selector;
                $tag = preg_match('/^[A-Za-z][\w-]*/', $match[1], $name) === 1 ? $name[0] : '';
                if ($tag === '' || $tag === 'button') {
                    $reachable[] = $selector;
                }
            }
        }

        return ['reachable' => $reachable, 'all' => $all];
    }

    /**
     * @return list<string>
     */
    private static function styleSheets(): array
    {
        $root = self::repositoryRoot();
        $sheets = array_merge(
            (array) glob($root . '/templates/*.css'),
            (array) glob($root . '/templates/*/*.css'),
            (array) glob($root . '/templates_twig/*/assets/*.css')
        );

        return array_values(array_filter($sheets, 'is_string'));
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

    public function testTheStyleSheetsAreFound(): void
    {
        $sheets = self::styleSheets();

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
        $motd = self::selectorsFor('motd');

        self::assertNotEmpty($motd['all'], 'motd should still be defined in the themes');
        self::assertEmpty(
            $motd['reachable'],
            'motd is defined only as a.motd; a button wearing it is unstyled, which is what #1540 was about'
        );
    }

    public function testTheCheckAcceptsAnUnqualifiedClass(): void
    {
        $button = self::selectorsFor('button');

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

                $found = self::selectorsFor($class);
                if ($found['reachable'] !== []) {
                    continue;
                }

                $unstyled[] = sprintf(
                    "  '%s' (%s) -- %s",
                    $class,
                    implode(', ', array_unique($files)),
                    $found['all'] === []
                        ? 'defined in no stylesheet at all'
                        : sprintf('defined only as %s, which cannot match a button', $found['all'][0])
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

        $unstyled = [];
        foreach ($defaults as $classAttribute) {
            foreach (preg_split('/\s+/', trim($classAttribute)) ?: [] as $class) {
                if ($class !== '' && self::selectorsFor($class)['reachable'] === []) {
                    $unstyled[] = $class;
                }
            }
        }

        self::assertSame([], $unstyled, 'default classes no theme can style: ' . implode(', ', $unstyled));
    }
}
