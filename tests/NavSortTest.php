<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

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
            'navitem' => '<a href="{link}">{text}</a>'
        ]);
    }

    protected function tearDown(): void
    {
        global $session, $nav, $output;
        unset($session, $nav, $output);
        Template::getInstance()->setTemplate([]);
    }

    public function testAscendingSorting(): void
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

        $this->assertLessThan(strpos($navs, 'Z Item'), strpos($navs, 'A Item'));
        $this->assertLessThan(strpos($navs, 'Y Item'), strpos($navs, 'B Item'));
        $this->assertLessThan(strpos($navs, 'X Item'), strpos($navs, 'C Item'));
        $this->assertLessThan(strpos($navs, 'Beta'), strpos($navs, 'Alpha'));
    }

    public function testDescendingSorting(): void
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

        $this->assertLessThan(strpos($navs, 'A Item'), strpos($navs, 'B Item'));
        $this->assertLessThan(strpos($navs, 'A1'), strpos($navs, 'B1'));
        $this->assertLessThan(strpos($navs, 'Alpha'), strpos($navs, 'Beta'));
    }

    public function testHeaderAscendingSorting(): void
    {
        global $session;
        Nav::addHeader('Beta', false);
        Nav::add('B Item', 'b.php');
        Nav::addHeader('Alpha', false);
        Nav::add('A Item', 'a.php');

        $session['user']['prefs']['navsort_headers'] = 'asc';
        $session['user']['prefs']['navsort_subheaders'] = 'asc';

        $navs = strip_tags(Nav::buildNavs());

        $this->assertLessThan(strpos($navs, 'Alpha'), strpos($navs, 'Beta'));
    }

    public function testHeaderDescendingSorting(): void
    {
        global $session;
        Nav::addHeader('Alpha', false);
        Nav::add('A Item', 'a.php');
        Nav::addHeader('Beta', false);
        Nav::add('B Item', 'b.php');

        $session['user']['prefs']['sortedmenus'] = 'desc';
        $session['user']['prefs']['navsort_headers'] = 'asc';
        $session['user']['prefs']['navsort_subheaders'] = 'asc';

        $navs = strip_tags(Nav::buildNavs());

        $this->assertLessThan(strpos($navs, 'Alpha'), strpos($navs, 'Beta'));
    }

    public function testSortedMenusPreferenceDisablesSorting(): void
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

        $this->assertLessThan(strpos($navs, 'Alpha'), strpos($navs, 'Beta'));
        $this->assertLessThan(strpos($navs, 'Z Item'), strpos($navs, 'A Item'));
        $this->assertLessThan(strpos($navs, 'Y Item'), strpos($navs, 'B Item'));
    }
}
