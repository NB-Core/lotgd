<?php

declare(strict_types=1);

namespace Lotgd\Tests\Templates;

use PHPUnit\Framework\TestCase;

/**
 * The bundled templates keep the placeholders the engine fills in.
 *
 * All four legacy templates lost `{lang}` and `{meta_description}` in a
 * stylesheet round, replaced by literals -- `lang="en"` and a description
 * naming one particular installation's game. Two settings an operator can
 * configure (`defaultlanguage` and `meta_description`) therefore did nothing in
 * those themes, every install shipped somebody else's game name in its
 * `<meta>`, and a non-English game told browsers and screen readers it was
 * English. Nothing noticed: a template is data, and no test read it.
 *
 * The list is derived from `Lotgd\Page\Header` rather than written out here.
 * A restated list is one more copy to keep in step, and this whole file exists
 * because a copy drifted.
 */
final class BundledTemplatePlaceholdersTest extends TestCase
{
    /**
     * The blocks Header fills in: the page head and the popup head.
     *
     * Named as `Lotgd\Template::loadTemplate()` names them, since that is what
     * decides which text a `str_replace()` ever sees.
     */
    private const HEAD_BLOCKS = ['header', 'popuphead'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every `{placeholder}` Header substitutes into a head block.
     *
     * @return list<string>
     */
    private static function headerPlaceholders(): array
    {
        $source = (string) file_get_contents(self::root() . '/src/Lotgd/Page/Header.php');
        preg_match_all("/str_replace\('\{([a-z_]+)\}'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * A legacy template's blocks, split the way the engine splits them.
     *
     * Mirrors `Lotgd\Template::loadTemplate()` (src/Lotgd/Template.php:351)
     * rather than calling it: that method reaches for Settings and the module
     * hooks, and neither has anything to do with the question here.
     *
     * @return array<string, string>
     */
    private static function blocksOf(string $path): array
    {
        $blocks = [];
        foreach (explode('<!--!', (string) file_get_contents($path)) as $chunk) {
            $end = strpos($chunk, '-->');
            if ($end === false) {
                continue;
            }

            $blocks[substr($chunk, 0, $end)] = substr($chunk, $end + 3);
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private static function legacyTemplates(): array
    {
        return array_values(array_filter((array) glob(self::root() . '/templates/*.htm'), 'is_string'));
    }

    /**
     * @return list<string>
     */
    private static function twigHeads(): array
    {
        return array_values(array_filter(array_merge(
            (array) glob(self::root() . '/templates_twig/*/page.twig'),
            (array) glob(self::root() . '/templates_twig/*/popup.twig')
        ), 'is_string'));
    }

    public function testEveryLegacyHeadCarriesEveryPlaceholderTheEngineFillsIn(): void
    {
        $placeholders = self::headerPlaceholders();

        self::assertContains('lang', $placeholders, 'precondition: the engine still substitutes these');
        self::assertContains('meta_description', $placeholders);

        $templates = self::legacyTemplates();
        self::assertNotEmpty($templates, 'precondition: there are bundled legacy templates to check');

        $missing = [];
        foreach ($templates as $template) {
            $blocks = self::blocksOf($template);

            foreach (self::HEAD_BLOCKS as $block) {
                self::assertArrayHasKey(
                    $block,
                    $blocks,
                    basename($template) . " has no '$block' block, so the engine has nothing to fill in"
                );

                foreach ($placeholders as $placeholder) {
                    if (!str_contains($blocks[$block], '{' . $placeholder . '}')) {
                        $missing[] = sprintf('%s [%s]: {%s}', basename($template), $block, $placeholder);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "A bundled template dropped a placeholder the engine substitutes:\n" . implode("\n", $missing)
                . "\n\nWhatever stands there instead is a constant, and the setting behind it stops working."
        );
    }

    /**
     * The two that were replaced by literals, asserted on the attribute itself.
     *
     * The test above would pass for a template that kept `{meta_description}`
     * in a comment while the real `<meta>` carried a hard-coded sentence. This
     * one reads the attribute, so what it checks is what the browser gets.
     *
     * Per head block rather than per file, and presence before value: the
     * first version compared values only, so a template that deleted the
     * `<html lang>` or the description outright matched nothing and was
     * recorded as clean -- silencing the check by removing the thing it
     * checks, which loses the setting just as completely as hard-coding it.
     * Reported by Copilot.
     */
    public function testEveryHeadDeclaresTheLanguageAndDescriptionAsPlaceholders(): void
    {
        $offenders = [];

        foreach (self::legacyTemplates() as $template) {
            $blocks = self::blocksOf($template);
            foreach (self::HEAD_BLOCKS as $block) {
                $offenders = array_merge($offenders, self::headOffenders(
                    $blocks[$block] ?? '',
                    basename($template) . " [$block]",
                    '{lang}',
                    '{meta_description}'
                ));
            }
        }

        foreach (self::twigHeads() as $template) {
            $offenders = array_merge($offenders, self::headOffenders(
                (string) file_get_contents($template),
                basename(dirname($template)) . '/' . basename($template),
                '{{ lang }}',
                '{{ meta_description }}'
            ));
        }

        self::assertSame(
            [],
            $offenders,
            "A bundled head does not leave the language and description to the operator:\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * What is wrong with one head block, if anything.
     *
     * Each attribute has to be there exactly once and carry the placeholder.
     * Absence is reported as loudly as a literal, because both end the same
     * way: the setting behind it stops reaching the page.
     *
     * @return list<string>
     */
    private static function headOffenders(string $markup, string $label, string $lang, string $description): array
    {
        $offenders = [];

        $checks = [
            ['<html lang>', '/<html[^>]*\\blang=(["\'])(.*?)\\1/i', 2, $lang],
            [
                '<meta name="description">',
                '/<meta[^>]*\\bname=(["\'])description\\1[^>]*\\bcontent=(["\'])(.*?)\\2/i',
                3,
                $description,
            ],
        ];

        foreach ($checks as [$what, $pattern, $group, $expected]) {
            $found = preg_match_all($pattern, $markup, $matches);

            if ($found !== 1) {
                $offenders[] = sprintf('%s: %s appears %d times, expected once', $label, $what, (int) $found);
                continue;
            }

            if (trim($matches[$group][0]) !== $expected) {
                $offenders[] = sprintf('%s: %s carries %s, expected %s', $label, $what, $matches[$group][0], $expected);
            }
        }

        return $offenders;
    }

    /**
     * A template loads its stylesheets in the same order in every head it has.
     *
     * `modern.htm` did not: its popup head loaded the shared sheet before its
     * own and its page head after, so the same class resolved differently in
     * the two views of one theme -- the action row's column gap came out 4px on
     * a page and 8px in the mail popup. Source order is the whole of how these
     * rules are decided, since a theme rule and a shared rule tie on
     * specificity, so an order that changes between blocks is a theme
     * disagreeing with itself.
     *
     * The order itself is not prescribed here; only that a template agrees
     * with itself. Which sheet a theme wants last is the theme's business.
     */
    public function testEachLegacyTemplateLoadsItsStylesheetsInOneOrder(): void
    {
        foreach (self::legacyTemplates() as $template) {
            $orders = [];
            foreach (self::blocksOf($template) as $name => $body) {
                preg_match_all('/href=(["\'])(templates\/[^"\']+\.css)\1/', $body, $matches);
                if ($matches[2] !== []) {
                    $orders[$name] = $matches[2];
                }
            }

            self::assertGreaterThan(
                1,
                count($orders),
                basename($template) . ' has fewer than two heads linking stylesheets, so this proved nothing'
            );

            $first = null;
            foreach ($orders as $name => $sheets) {
                if ($first === null) {
                    $first = $sheets;
                    continue;
                }

                self::assertSame(
                    $first,
                    $sheets,
                    basename($template) . " loads its stylesheets in a different order in '$name'"
                );
            }
        }
    }
}
