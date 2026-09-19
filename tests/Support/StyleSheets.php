<?php

declare(strict_types=1);

namespace Lotgd\Tests\Support;

/**
 * What the bundled themes can and cannot style.
 *
 * Extracted from ButtonClassesAreStyledTest so that a second test can ask the
 * same question of classes that no longer exist as literals anywhere. Since
 * `Forms::actionBar()` composes its control class from the container's
 * ('button ' . $class . '__link'), reading the source no longer answers "which
 * classes reach a button"; rendering a row and looking at what came out does.
 * Both callers must judge a class by the same rule, or the weaker one becomes
 * the one that matters.
 *
 * The rule itself, and why it is stricter than it looks:
 *
 *   - "Defined somewhere" is not enough. `a.motd` is defined in every bundled
 *     theme and cannot match a button, which is the whole of what #1540 was.
 *   - "Appears in a selector" is not enough either. `.mail-nav__link a` styles
 *     the anchor inside the control, not the control. Only the rightmost
 *     compound of a selector describes the element a rule applies to.
 */
final class StyleSheets
{
    /** @var list<string>|null */
    private static ?array $selectors = null;

    /**
     * @var array<string, list<string>>|null
     */
    private static ?array $themes = null;

    /**
     * @var array<string, list<string>>|null
     */
    private static ?array $themeSheets = null;

    /**
     * Selectors that mention $class, and the subset a `<button>` could match.
     *
     * @return array{reachable: list<string>, all: list<string>}
     */
    public static function selectorsFor(string $class): array
    {
        $reachable = [];
        $all = [];

        foreach (self::allSelectors() as $selector) {
            if (!self::mentionsClass($selector, $class)) {
                continue;
            }

            $all[] = $selector;
            if (self::isButtonReachable($selector, $class)) {
                $reachable[] = $selector;
            }
        }

        return ['reachable' => $reachable, 'all' => $all];
    }

    /**
     * Whether a `<button>` wearing $class could match $selector.
     *
     * Two ways it could not: the rule names another element (`a.motd`), or the
     * class sits left of a combinator, where it selects an ancestor and the
     * rule styles something else (`.mail-nav__link a`).
     */
    public static function isButtonReachable(string $selector, string $class): bool
    {
        $parts = preg_split('/\s*[>+~]\s*|\s+/', trim($selector)) ?: [];
        $compound = (string) end($parts);

        if (!self::mentionsClass($compound, $class)) {
            return false;
        }

        $tag = preg_match('/^[A-Za-z][\w-]*/', $compound, $name) === 1 ? $name[0] : '';

        return $tag === '' || $tag === 'button';
    }

    public static function mentionsClass(string $selector, string $class): bool
    {
        return preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $selector) === 1;
    }

    /**
     * The stylesheets each theme actually loads, in load order.
     *
     * Read from the templates rather than assumed, because the answer changed
     * underneath this file once already. It used to skip everything under
     * `templates/common/` on the grounds that the only thing there was a shared
     * palette -- true of `colors.css`, and wrong the moment `sidebar.css`
     * arrived carrying the `.button` geometry and the action-row layout that
     * all four legacy themes now depend on. The blanket path rule swallowed it,
     * so those themes appeared to style nothing they in fact style, and a
     * deletion of the shared sheet would have gone unnoticed by every
     * assertion here.
     *
     * A theme is therefore the set of sheets its template links, which is the
     * only definition a browser agrees with. The two shapes are the four
     * legacy `.htm` templates and the six Twig `page.twig` ones; the Twig
     * themes do not link `sidebar.css`, and this reports that rather than
     * papering over it.
     *
     * Keyed by the theme's own sheet so a failure names something a reader can
     * open.
     *
     * @return array<string, list<string>>
     */
    public static function themeSheets(): array
    {
        if (self::$themeSheets !== null) {
            return self::$themeSheets;
        }

        $root = dirname(__DIR__, 2);
        $themes = [];

        foreach ((array) glob($root . '/templates/*.htm') as $template) {
            $html = (string) file_get_contents((string) $template);
            preg_match_all('/href=[\'"](templates\/[^\'"]+\.css)[\'"]/', $html, $matches);
            $themes += self::themeFor($root, (string) $template, array_values(array_unique($matches[1])));
        }

        foreach ((array) glob($root . '/templates_twig/*/page.twig') as $template) {
            $name = basename(dirname((string) $template));
            $twig = (string) file_get_contents((string) $template);
            $sheets = [];
            if (str_contains($twig, '/templates/common/colors.css')) {
                $sheets[] = 'templates/common/colors.css';
            }
            if (str_contains($twig, "template_path ~ '/assets/style.css'")) {
                $sheets[] = 'templates_twig/' . $name . '/assets/style.css';
            }
            $themes += self::themeFor($root, (string) $template, $sheets);
        }

        self::$themeSheets = $themes;

        return $themes;
    }

    /**
     * One theme's entry: its own sheet as the key, every sheet it loads as the
     * value.
     *
     * The theme's own sheet is the one that is not shared. A template that
     * links none has no theme to name, and returning nothing for it would make
     * this map quietly cover less than the caller believes -- a bundled theme
     * dropping out of every assertion here without a word. So it throws, which
     * is what the docblock used to promise while the code skipped. Reported by
     * Copilot.
     *
     * A sheet the template links but that is not on disk is left out rather
     * than fatal, and deliberately: that is how deleting `sidebar.css` makes
     * the themes which depend on it report what they lost.
     *
     * @param list<string> $relative
     *
     * @return array<string, list<string>>
     */
    private static function themeFor(string $root, string $template, array $relative): array
    {
        $own = null;
        $paths = [];

        foreach ($relative as $sheet) {
            $path = $root . '/' . $sheet;
            if (!is_file($path)) {
                continue;
            }

            $paths[] = $path;
            if (!str_starts_with($sheet, 'templates/common/')) {
                $own = $sheet;
            }
        }

        if ($own === null) {
            throw new \RuntimeException(sprintf(
                'No theme stylesheet of its own is linked by %s, so it would drop out of every '
                    . 'assertion about the bundled themes unnoticed.',
                substr($template, strlen($root) + 1)
            ));
        }

        return [$own => $paths];
    }

    /**
     * The themes a player can actually be wearing, as sheet => selectors.
     *
     * Asking whether a class is styled *somewhere* is too coarse a question,
     * and a mutation proved it rather than an argument: deleting the whole
     * `.action-bar` rule from one theme left every assertion green, because
     * nine other themes still defined it. A player using that theme would have
     * had the unstyled row -- which is #1540 exactly, one level up.
     *
     * The selectors of every sheet the theme loads, merged, because that is
     * what reaches the page. See themeSheets() for why this is read from the
     * templates.
     *
     * @return array<string, list<string>>
     */
    public static function themes(): array
    {
        if (self::$themes !== null) {
            return self::$themes;
        }

        $themes = [];
        foreach (self::themeSheets() as $theme => $sheets) {
            $selectors = [];
            foreach ($sheets as $sheet) {
                $selectors = array_merge($selectors, self::selectorsIn((string) file_get_contents($sheet)));
            }

            $themes[$theme] = $selectors;
        }

        self::$themes = $themes;

        return $themes;
    }

    /**
     * The themes in which a button wearing $class would be unstyled.
     *
     * @return list<string>
     */
    public static function themesMissing(string $class): array
    {
        $missing = [];

        foreach (self::themes() as $theme => $selectors) {
            foreach ($selectors as $selector) {
                if (self::isButtonReachable($selector, $class)) {
                    continue 2;
                }
            }

            $missing[] = $theme;
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        $root = dirname(__DIR__, 2);
        $sheets = array_merge(
            (array) glob($root . '/templates/*.css'),
            (array) glob($root . '/templates/*/*.css'),
            (array) glob($root . '/templates_twig/*/assets/*.css')
        );

        return array_values(array_filter($sheets, 'is_string'));
    }

    /**
     * Every selector in every bundled stylesheet, parsed once per run.
     *
     * 81 KB across eleven sheets, which is worth parsing once rather than per
     * class. Derived only from files that do not change while the suite runs,
     * so it cannot make one test's result depend on another having run first.
     *
     * @return list<string>
     */
    public static function allSelectors(): array
    {
        if (self::$selectors !== null) {
            return self::$selectors;
        }

        $selectors = [];
        foreach (self::paths() as $sheet) {
            $selectors = array_merge($selectors, self::selectorsIn((string) file_get_contents($sheet)));
        }

        self::$selectors = $selectors;

        return $selectors;
    }

    /**
     * The rules of a stylesheet, in source order: selector, property, index.
     *
     * Reachability was not the only question, and a review round is what
     * showed it. A class can be defined, reachable on a button, and still
     * wrong: `.action-bar__link { color: inherit }` has the same specificity
     * as `.button`, so appending it to the sheet made it win and stripped the
     * themed colour from every control in seven of ten themes. Every existing
     * assertion stayed green, because the class *was* styled -- just not the
     * way the theme meant.
     *
     * So the rules come back ordered, and a caller can ask which declaration
     * a browser would actually use.
     *
     * Declarations are read shallowly: `prop: value` pairs at the top level of
     * a block. Enough for the question being asked, which is about a handful of
     * flat properties, and honest about what it cannot see.
     *
     * @return list<array{selector: string, property: string, order: int}>
     */
    public static function declarationsIn(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        $rules = [];
        $order = 0;
        $offset = 0;

        while (($open = strpos($css, '{', $offset)) !== false) {
            $close = strpos($css, '}', $open);
            if ($close === false) {
                break;
            }

            // -1 rather than 0 when there is no preceding brace, because the
            // prelude then starts at offset 0. Casting strrpos()'s false to 0
            // ate the first character of the first prelude in a string -- for
            // `@media ... {` that is the `@`, so the whole at-rule read as an
            // ordinary selector. Real sheets hid it behind a leading comment
            // or blank line; a unit test on a bare string did not.
            $before = substr($css, 0, $open);
            $lastClose = strrpos($before, '}');
            $lastOpen = strrpos($before, '{');
            $preludeStart = max(
                $lastClose === false ? -1 : $lastClose,
                $lastOpen === false ? -1 : $lastOpen
            );
            $prelude = trim(substr($css, $preludeStart + 1, $open - $preludeStart - 1));

            // An at-rule's `}` is not its own: `$close` is the end of the
            // FIRST RULE NESTED INSIDE IT. Resuming past that skipped that
            // rule entirely, so a theme overriding .button inside a @media
            // block went unseen -- which is the guarantee this file exists to
            // give. Resume just inside the brace instead, so the next `{` the
            // loop finds is the first nested selector's.
            if (str_starts_with($prelude, '@')) {
                $offset = $open + 1;
                continue;
            }

            $body = substr($css, $open + 1, $close - $open - 1);
            $offset = $close + 1;

            if ($prelude === '') {
                continue;
            }

            foreach (explode(',', $prelude) as $selector) {
                $selector = trim((string) preg_replace('/\s+/', ' ', $selector));
                if ($selector === '') {
                    continue;
                }

                foreach (explode(';', $body) as $declaration) {
                    if (!str_contains($declaration, ':')) {
                        continue;
                    }

                    $property = strtolower(trim(explode(':', $declaration, 2)[0]));
                    if ($property === '' || !preg_match('/^[a-z-]+$/', $property)) {
                        continue;
                    }

                    $rules[] = ['selector' => $selector, 'property' => $property, 'order' => $order++];
                }
            }
        }

        return $rules;
    }

    /**
     * Properties where $class would override `.button` on a control wearing both.
     *
     * Both are single-class selectors, so specificity ties and source order
     * decides. A control carries `button` *and* the row class, so anything the
     * row class sets later is what the browser uses -- which is how a theme
     * loses its own button colour without any assertion noticing.
     *
     * Returns theme => properties, so the failure names the themes.
     *
     * Across every sheet the theme loads, in load order, rather than each on
     * its own: the two rules need not live in the same file. `.button`'s
     * geometry sits in the shared `templates/common/sidebar.css` now, which
     * every legacy template links *after* its own -- so a per-file reading
     * cannot see which of the two a browser would use, and would answer for
     * the wrong one.
     *
     * @return array<string, list<string>>
     */
    public static function propertiesOverridingButton(string $class): array
    {
        $clashes = [];

        foreach (self::themeSheets() as $theme => $sheets) {
            $button = [];
            $row = [];
            $order = 0;

            foreach ($sheets as $sheet) {
                foreach (self::declarationsIn((string) file_get_contents($sheet)) as $rule) {
                    // The declaration's own index restarts per sheet, so the
                    // sheets are re-numbered into one sequence here; comparing
                    // the raw indices would say a later sheet's first rule
                    // precedes an earlier sheet's last.
                    $order++;

                    if ($rule['selector'] === '.button') {
                        $button[$rule['property']] = $order;
                        continue;
                    }

                    if ($rule['selector'] === '.' . $class) {
                        $row[$rule['property']] = $order;
                    }
                }
            }

            $properties = [];
            foreach ($row as $property => $position) {
                if (isset($button[$property]) && $position > $button[$property]) {
                    $properties[] = $property;
                }
            }

            if ($properties !== []) {
                sort($properties);
                $clashes[$theme] = $properties;
            }
        }

        return $clashes;
    }

    /**
     * Every selector in a stylesheet, one per comma-separated entry.
     *
     * At-rule preludes (`@media (max-width: 768px)`) are not selectors and are
     * dropped; the rules nested inside them are reached anyway, because the
     * prelude ends at its own `{` and the block that follows is read normally.
     *
     * @return list<string>
     */
    public static function selectorsIn(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        $selectors = [];
        $buffer = '';
        $length = strlen($css);

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];

            // A declaration ends the run of text that could have been a
            // prelude; so does the end of a block.
            if ($char === ';' || $char === '}') {
                $buffer = '';
                continue;
            }

            if ($char !== '{') {
                $buffer .= $char;
                continue;
            }

            $prelude = trim($buffer);
            $buffer = '';
            if ($prelude === '' || str_starts_with($prelude, '@')) {
                continue;
            }

            foreach (explode(',', $prelude) as $selector) {
                $selector = trim((string) preg_replace('/\s+/', ' ', $selector));
                if ($selector !== '') {
                    $selectors[] = $selector;
                }
            }
        }

        return $selectors;
    }
}
