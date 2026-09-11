<?php

declare(strict_types=1);

namespace Lotgd\Tests\Nav;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

/**
 * Ordering of the navigation column.
 *
 * The three preferences are named one level off from what they control, which
 * is worth stating once because it is the trap this file fell into:
 *
 *   sortedmenus        -> section order   (the headers)
 *   navsort_headers    -> subsection order (the sub-headers)
 *   navsort_subheaders -> item order       (the links)
 *
 * See Nav::buildNavs(), which reads them in that order. An unset preference
 * means "off", and with all three off the column keeps insertion order.
 */
final class NavSortTest extends TestCase
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
            'navitem' => '<a href="{link}">{text}</a>',
        ]);
    }

    protected function tearDown(): void
    {
        global $session, $nav, $output;

        unset($session, $nav, $output);
        Template::getInstance()->setTemplate([]);
    }

    /**
     * Assert that $first appears before $second in the rendered column.
     */
    private static function assertOrder(string $navs, string $first, string $second): void
    {
        $firstAt = strpos($navs, $first);
        $secondAt = strpos($navs, $second);

        self::assertNotFalse($firstAt, "'{$first}' is missing from the column");
        self::assertNotFalse($secondAt, "'{$second}' is missing from the column");
        self::assertLessThan($secondAt, $firstAt, "'{$first}' should come before '{$second}'");
    }

    public function testEverythingSortsAscending(): void
    {
        global $session;

        Nav::addHeader('Main', false);
        Nav::add('Z Item', 'z.php');
        Nav::add('A Item', 'a.php');
        Nav::addSubHeader('Beta');
        Nav::add('Y Item', 'y.php');
        Nav::add('B Item', 'b.php');
        Nav::addSubHeader('Alpha');
        Nav::add('X Item', 'x.php');
        Nav::add('C Item', 'c.php');

        $session['user']['prefs']['sortedmenus'] = 'asc';
        $session['user']['prefs']['navsort_headers'] = 'asc';
        $session['user']['prefs']['navsort_subheaders'] = 'asc';

        $navs = strip_tags(Nav::buildNavs());

        self::assertOrder($navs, 'A Item', 'Z Item');
        self::assertOrder($navs, 'B Item', 'Y Item');
        self::assertOrder($navs, 'C Item', 'X Item');
        self::assertOrder($navs, 'Alpha', 'Beta');
    }

    public function testSubHeadersAndItemsSortDescending(): void
    {
        global $session;

        Nav::addHeader('Main', false);
        Nav::add('A Item', 'a.php');
        Nav::add('B Item', 'b.php');
        Nav::addSubHeader('Alpha');
        Nav::add('A1', 'a1.php');
        Nav::add('B1', 'b1.php');
        Nav::addSubHeader('Beta');
        Nav::add('A2', 'a2.php');
        Nav::add('B2', 'b2.php');

        $session['user']['prefs']['navsort_headers'] = 'desc';
        $session['user']['prefs']['navsort_subheaders'] = 'desc';

        $navs = strip_tags(Nav::buildNavs());

        self::assertOrder($navs, 'B Item', 'A Item');
        self::assertOrder($navs, 'B1', 'A1');
        self::assertOrder($navs, 'Beta', 'Alpha');
    }

    /**
     * Headers sorted ascending -- the case this file did not have.
     *
     * The old testHeaderAscendingSorting() set navsort_headers, which governs
     * sub-headers, and left sortedmenus unset. Headers were therefore not
     * sorted at all, and its assertion held only because insertion order
     * happened to match.
     *
     * The gap that left is narrow and was worth pinning down: breaking
     * ascending sorting outright is caught by testEverythingSortsAscending,
     * and removing header sorting outright by testHeadersSortDescending. What
     * nothing caught was ascending sorting failing at the header level alone
     * -- forcing $sectionOrder from 'asc' to 'off' in buildNavs() leaves the
     * old file green and fails this test.
     */
    public function testHeadersSortAscending(): void
    {
        global $session;

        Nav::addHeader('Beta', false);
        Nav::add('B Item', 'b.php');
        Nav::addHeader('Alpha', false);
        Nav::add('A Item', 'a.php');

        $session['user']['prefs']['sortedmenus'] = 'asc';

        $navs = strip_tags(Nav::buildNavs());

        self::assertOrder($navs, 'Alpha', 'Beta');
    }

    public function testHeadersSortDescending(): void
    {
        global $session;

        Nav::addHeader('Alpha', false);
        Nav::add('A Item', 'a.php');
        Nav::addHeader('Beta', false);
        Nav::add('B Item', 'b.php');

        $session['user']['prefs']['sortedmenus'] = 'desc';

        $navs = strip_tags(Nav::buildNavs());

        self::assertOrder($navs, 'Beta', 'Alpha');
    }

    /**
     * The sub-header preference must not reach the headers.
     *
     * This is what the old testHeaderAscendingSorting() was really checking,
     * under a name that claimed otherwise. It is worth keeping under an honest
     * one: the three preferences are independent, and a player who sorts their
     * sub-headers has not asked for their headers to move.
     */
    public function testSubHeaderPreferenceLeavesHeadersInInsertionOrder(): void
    {
        global $session;

        Nav::addHeader('Beta', false);
        Nav::add('B Item', 'b.php');
        Nav::addHeader('Alpha', false);
        Nav::add('A Item', 'a.php');

        $session['user']['prefs']['navsort_headers'] = 'asc';
        $session['user']['prefs']['navsort_subheaders'] = 'asc';

        $navs = strip_tags(Nav::buildNavs());

        self::assertOrder($navs, 'Beta', 'Alpha');
    }

    /**
     * sortedmenus = 0 turns off the header sort, and only that.
     *
     * The three preferences are independent, so the items keep sorting under
     * navsort_subheaders. The old name for this case, "SortedMenusPreference
     * DisablesSorting", claimed the whole column froze; it never did, and the
     * assertions below are the ones the old test actually made.
     */
    public function testSortedMenusOffLeavesOnlyTheHeadersUnsorted(): void
    {
        global $session;

        Nav::addHeader('Beta', false);
        Nav::add('Z Item', 'z.php');
        Nav::add('A Item', 'a.php');
        Nav::addHeader('Alpha', false);
        Nav::add('Y Item', 'y.php');
        Nav::add('B Item', 'b.php');

        $session['user']['prefs']['navsort_headers'] = 'asc';
        $session['user']['prefs']['navsort_subheaders'] = 'asc';
        $session['user']['prefs']['sortedmenus'] = 0;

        $navs = strip_tags(Nav::buildNavs());

        // Headers stay where they were added.
        self::assertOrder($navs, 'Beta', 'Alpha');
        // Items still sort, because that is a different preference.
        self::assertOrder($navs, 'A Item', 'Z Item');
        self::assertOrder($navs, 'B Item', 'Y Item');
    }
}
