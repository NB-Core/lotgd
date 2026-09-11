<?php

declare(strict_types=1);

namespace Lotgd\Tests\Nav;

use Lotgd\Nav;
use Lotgd\Nav\NavigationItem;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

/**
 * What the navigation column actually renders: headers, sub-headers, items,
 * colour codes and access keys.
 *
 * This replaces six classes that each carried a copy of the same twelve-line
 * setUp for one or two assertions. Two of those copies installed the template
 * as the $template global while Template::templateReplace() reads the Template
 * singleton, so buildNavs() rendered nothing at all for them -- which made
 * NavFontResetTest, whose every assertion is assertStringNotContainsString,
 * a check that an empty string does not contain something. Those cases are
 * kept here against a real template, each paired with a positive control so a
 * silent empty render cannot pass for a correct one again.
 */
final class NavRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $nav, $output;

        $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
        $nav = '';
        $output = new Output();
        Nav::clearNav();
        Template::getInstance()->setTemplate([
            'navhead' => '<span class="navhead">{title}</span>',
            'navheadsub' => '<span class="navheadsub">{title}</span>',
            'navitem' => '<a href="{link}"{accesskey}{popup}>{text}</a>',
            'navhelp' => '<span class="navhelp">{text}</span>',
        ]);
    }

    protected function tearDown(): void
    {
        global $session, $nav, $output;

        unset($session, $nav, $output);
        Template::getInstance()->setTemplate([]);
    }

    public function testHeaderRendersWithoutColourWhenNoneAsked(): void
    {
        Nav::addHeader('Section', false);
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();

        self::assertStringContainsString('<span class="navhead">Section</span>', $navs);
        self::assertStringNotContainsString('colLtBlue', $navs);
    }

    public function testColouredHeaderRendersItsColour(): void
    {
        Nav::addColoredHeader('`!Section', false);
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();

        self::assertMatchesRegularExpression(
            '/<span class="navhead">.*<span class=\'colLtBlue\'>Section<\/span>.*<\/span>/',
            $navs
        );
    }

    public function testHeaderWithNoLinksIsSuppressed(): void
    {
        self::assertSame('', Nav::buildNavs(), 'nothing added yet');

        Nav::addColoredHeader('`!Empty');

        self::assertSame('', Nav::buildNavs(), 'a header on its own is not a section');
    }

    public function testHeaderIsSuppressedWhenItsOnlyLinkIsBlocked(): void
    {
        Nav::addColoredHeader('`!Section', false);
        Nav::add('Link', 'foo.php');

        // Positive control: the section renders before the block is applied.
        self::assertStringContainsString('foo.php', Nav::buildNavs());

        Nav::clearNav();
        Nav::addColoredHeader('`!Section', false);
        Nav::add('Link', 'foo.php');
        Nav::blockNav('foo.php');

        self::assertSame('', Nav::buildNavs());
    }

    public function testColouredItemUnderColouredHeader(): void
    {
        Nav::addColoredHeader('`!Section', false);
        Nav::add('`$Link', 'foo.php');

        $navs = Nav::buildNavs();

        self::assertStringContainsString('colLtBlue', $navs, 'the header colour');
        self::assertStringContainsString('colLtRed', $navs, 'the item colour');
        self::assertStringContainsString('foo.php', $navs);
    }

    public function testColouredSubHeaderRendersItsColour(): void
    {
        Nav::addHeader('Main', false);
        Nav::addColoredSubHeader('`!Sub');
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();

        // The old test paired this with assertStringContainsString('</span>'),
        // which any template in this file satisfies. Pin the sub-header slot
        // and the colour instead.
        self::assertStringContainsString('<span class="navheadsub">', $navs);
        self::assertStringContainsString('colLtBlue', $navs);
    }

    public function testSubHeaderRendersWithItsLink(): void
    {
        Nav::addHeader('Main', false);
        Nav::addSubHeader('Sub');
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();

        self::assertStringContainsString('<span class="navheadsub">Sub</span>', $navs);
        self::assertStringContainsString('foo.php', $navs);
    }

    public function testNavigationItemRendersALink(): void
    {
        $html = (new NavigationItem('Home', 'index.php'))->render();

        self::assertStringContainsString('index.php', $html);
        self::assertStringContainsString('navhi', $html, 'the access-key highlight');
    }

    public function testNullLinkBehavesLikeAnEmptyOne(): void
    {
        $expected = Nav::privateAddNav('Help', '');
        Nav::clearNav();
        $actual = Nav::privateAddNav('Help', null);

        // Positive control: with the template installed both really render
        // something. Without it they were equal because both were empty.
        self::assertNotSame('', (string) $expected);
        self::assertSame($expected, $actual);
    }

    /**
     * An unclosed colour code must not have its closing tag hoisted in front of
     * whatever nav comes next -- that is how one red link used to turn the rest
     * of the column red.
     */
    public function testUnclosedColourDoesNotCloseOverTheNextItem(): void
    {
        Nav::add('`!Red', 'red.php');
        Nav::add('Plain', 'plain.php');

        $navs = Nav::buildNavs();

        self::assertStringContainsString('plain.php', $navs, 'positive control: both items render');
        self::assertStringNotContainsString('</span><a href="plain.php">', $navs);
    }

    public function testUnclosedColourDoesNotCloseOverTheNextColour(): void
    {
        Nav::add('`!Red', 'red.php');
        Nav::add('`@Green', 'green.php');

        $navs = Nav::buildNavs();

        self::assertStringContainsString('green.php', $navs, 'positive control: both items render');
        self::assertStringNotContainsString('</span><span', $navs);
    }

    public function testExplicitAccessKeySurvivesTextThatCannotHighlightIt(): void
    {
        $html = (string) Nav::privateAddNav('z?###', 'foo.php');

        self::assertStringContainsString('href="foo.php"', $html);
        self::assertStringContainsString('accesskey="z"', $html);
        self::assertArrayHasKey('z', Nav::getQuickKeys());
        self::assertStringNotContainsString('`Hz`H', $html);
    }

    public function testAccessKeyOnUnhighlightableTextStillRenders(): void
    {
        $html = (string) Nav::privateAddNav('q?---', 'bar.php');

        self::assertNotSame('', $html);
        self::assertArrayHasKey('q', Nav::getQuickKeys());
    }
}
