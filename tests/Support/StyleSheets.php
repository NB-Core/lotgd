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
     * The themes a player can actually be wearing, as sheet => selectors.
     *
     * Asking whether a class is styled *somewhere* is too coarse a question,
     * and a mutation proved it rather than an argument: deleting the whole
     * `.action-bar` rule from one theme left every assertion green, because
     * nine other themes still defined it. A player using that theme would have
     * had the unstyled row -- which is #1540 exactly, one level up.
     *
     * `templates/common/colors.css` is excluded: it is a shared palette loaded
     * *alongside* a theme (see Redirect.php and the page.twig of every twig
     * theme), not a theme anyone can select, so it is not expected to define
     * a button.
     *
     * @return array<string, list<string>>
     */
    public static function themes(): array
    {
        $themes = [];
        foreach (self::paths() as $sheet) {
            if (str_contains($sheet, '/templates/common/')) {
                continue;
            }

            $themes[$sheet] = self::selectorsIn((string) file_get_contents($sheet));
        }

        return $themes;
    }

    /**
     * The themes in which a button wearing $class would be unstyled.
     *
     * @return list<string>
     */
    public static function themesMissing(string $class): array
    {
        $root = dirname(__DIR__, 2);
        $missing = [];

        foreach (self::themes() as $sheet => $selectors) {
            foreach ($selectors as $selector) {
                if (self::isButtonReachable($selector, $class)) {
                    continue 2;
                }
            }

            $missing[] = substr($sheet, strlen($root) + 1);
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

            $preludeStart = (int) max(
                (int) strrpos(substr($css, 0, $open), '}'),
                (int) strrpos(substr($css, 0, $open), '{')
            );
            $prelude = trim(substr($css, $preludeStart + 1, $open - $preludeStart - 1));
            $body = substr($css, $open + 1, $close - $open - 1);
            $offset = $close + 1;

            if ($prelude === '' || str_starts_with($prelude, '@')) {
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
     * Returns sheet => properties, so the failure names the themes.
     *
     * @return array<string, list<string>>
     */
    public static function propertiesOverridingButton(string $class): array
    {
        $root = dirname(__DIR__, 2);
        $clashes = [];

        foreach (self::themes() as $sheet => $_) {
            $button = [];
            $row = [];

            foreach (self::declarationsIn((string) file_get_contents($sheet)) as $rule) {
                if ($rule['selector'] === '.button') {
                    $button[$rule['property']] = $rule['order'];
                    continue;
                }

                if ($rule['selector'] === '.' . $class) {
                    $row[$rule['property']] = $rule['order'];
                }
            }

            $properties = [];
            foreach ($row as $property => $order) {
                if (isset($button[$property]) && $order > $button[$property]) {
                    $properties[] = $property;
                }
            }

            if ($properties !== []) {
                sort($properties);
                $clashes[substr($sheet, strlen($root) + 1)] = $properties;
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
