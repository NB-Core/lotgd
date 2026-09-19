<?php

declare(strict_types=1);

namespace Lotgd\Tests\Templates;

use Lotgd\Page\Header;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What the legacy head substitution puts inside an attribute.
 *
 * `{lang}` and `{meta_description}` sit in quoted attributes of every bundled
 * legacy template, and both come from settings an operator edits in
 * `configuration.php`. The substitution was a bare `str_replace()`, so a
 * description containing a double quote closed the attribute and
 * `"><script>...</script>` became stored markup on every page of every legacy
 * theme. Measured on the real jade head before the escaping was added: the
 * script tag reached the page.
 *
 * The Twig themes were never exposed, because Twig escapes `{{ ... }}` by
 * default -- measured too, rather than assumed from the documentation. That is
 * why the fix belongs on this path alone.
 *
 * Reported by Codex on #1551, whose restoration of the placeholders is what
 * put the injection point back.
 *
 * In its own process because this file needs the real Lotgd\Page\Header, and
 * tests/DiagnosticsPageTest.php:40 eval()s a stand-in class of that name when
 * it does not already exist -- with autoloading disabled, so it does that even
 * though the production class is right there on disk. Whichever ran first
 * decided what this file got, and alphabetically that was the stand-in. A test
 * whose result depends on what ran before it is not a test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeadPlaceholdersAreEscapedTest extends TestCase
{
    /**
     * A value that closes the attribute it is placed in and opens a script.
     */
    private const BREAKOUT = '"><script>alert(document.cookie)</script><meta x="';

    private const HEAD = '<html lang="{lang}"><head>'
        . '<meta name="description" content="{meta_description}">'
        . '<title>{title}</title></head>';

    public function testADescriptionCannotCloseTheAttributeItSitsIn(): void
    {
        $filled = Header::fillHeadPlaceholders(self::HEAD, 'A Title', 'en', self::BREAKOUT);

        self::assertStringNotContainsString('<script>', $filled, 'the value escaped its attribute');
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $filled, 'and is rendered as text instead');
    }

    /**
     * The language is the same shape of value from the same settings page.
     */
    public function testALanguageCannotCloseTheAttributeItSitsIn(): void
    {
        $filled = Header::fillHeadPlaceholders(self::HEAD, 'A Title', self::BREAKOUT, 'A description');

        self::assertStringNotContainsString('<script>', $filled);
    }

    /**
     * The control: an ordinary value still arrives readable.
     *
     * Without it, "no script tag" would hold just as well for a method that
     * dropped the value on the floor.
     */
    public function testAnOrdinaryValueIsStillSubstituted(): void
    {
        $filled = Header::fillHeadPlaceholders(self::HEAD, 'A Title', 'de', 'Ein Browserspiel');

        self::assertStringContainsString('<html lang="de">', $filled);
        self::assertStringContainsString('content="Ein Browserspiel"', $filled);
        self::assertStringNotContainsString('{', $filled, 'no placeholder is left behind');
    }

    /**
     * Escaping changes the bytes, not the value.
     *
     * The first version of this test asserted that an apostrophe survives
     * literally, and it does not -- Escape::html() uses ENT_QUOTES, so
     * `the game's own` is stored as `the game&#039;s own`. That is the right
     * behaviour and the wrong assertion: what a search engine or a screen
     * reader receives is the *parsed* attribute, so that is what is asserted
     * here. It holds for the hostile value too, which is the point: the text
     * arrives intact and the markup does not.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function descriptions(): iterable
    {
        yield 'an apostrophe' => ["the game's own"];
        yield 'a double quote' => ['the "best" game'];
        yield 'an ampersand' => ['swords & sorcery'];
        yield 'the break-out attempt' => [self::BREAKOUT];
    }

    #[DataProvider('descriptions')]
    public function testTheDescriptionArrivesIntactOnceTheBrowserParsesIt(string $description): void
    {
        $filled = Header::fillHeadPlaceholders(self::HEAD, 'A Title', 'en', $description);

        self::assertSame(1, preg_match('/content="([^"]*)"/', $filled, $match), 'the attribute stayed one attribute');
        self::assertSame($description, html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
    }

    /**
     * The title is not escaped, and this says why rather than that it is fine.
     *
     * The first version of this test gave the wrong reason -- that the title
     * had been through Sanitize::sanitize(). That sanitiser strips colour
     * codes; it is not an escaper and buys nothing against markup. Copilot
     * noticed the claim was load-bearing where it was not earned.
     *
     * The real reason is the second assertion: pages/clan/detail.php:121 asks
     * for "Clan Membership for %s &lt;%s&gt;", so a title carrying entities is
     * ordinary and escaping would print `&lt;` to the reader. The price is the
     * first assertion -- raw markup in a title reaches the page -- and it is
     * asserted rather than hidden, because a known limit someone can read is
     * worth more than a comfortable silence. Nothing in core builds a title
     * from player input; a caller that did would escape at that point, where
     * what the value is is still known.
     */
    public function testTheTitleIsNotEscaped(): void
    {
        $markup = Header::fillHeadPlaceholders(self::HEAD, 'Ye Olde <b>Poste</b>', 'en', 'A description');

        self::assertStringContainsString(
            '<title>Ye Olde <b>Poste</b></title>',
            $markup,
            'the known limit: a title is placed as markup'
        );

        $entities = Header::fillHeadPlaceholders(self::HEAD, 'Clan Membership for Reds &lt;RED&gt;', 'en', 'A');

        self::assertStringContainsString(
            '<title>Clan Membership for Reds &lt;RED&gt;</title>',
            $entities,
            'and the reason for it: escaping would double-encode a title core writes'
        );
    }
}
