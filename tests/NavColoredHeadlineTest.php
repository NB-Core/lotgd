<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

final class NavColoredHeadlineTest extends TestCase
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
            'navitem' => '<a href="{link}">{text}</a>'
        ]);
    }

    protected function tearDown(): void
    {
        global $session, $nav, $output;
        unset($session, $nav, $output);
        Template::getInstance()->setTemplate([]);
    }

    public function testRegularHeadlineUncolored(): void
    {
        Nav::addHeader('Section', false);
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();
        $this->assertStringContainsString('<span class="navhead">Section</span>', $navs);
        $this->assertStringNotContainsString('colLtBlue', $navs);
    }

    public function testColoredHeadlineRendersColors(): void
    {
        Nav::addColoredHeadline('`!Section', false);
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();
        $this->assertMatchesRegularExpression('/<span class="navhead">.*<span class=\'colLtBlue\'>Section<\/span>.*<\/span>/', $navs);
    }

    public function testColoredHeadlineSkippedWhenNoLinks(): void
    {
        $navs = Nav::buildNavs();
        $this->assertSame('', $navs);

        Nav::addColoredHeadline('`!Empty');
        $navs = Nav::buildNavs();
        $this->assertSame('', $navs);
    }

    public function testColoredHeadlineWithBlockedLink(): void
    {
        Nav::addColoredHeadline('`!Section', false);
        Nav::add('Link', 'foo.php');
        Nav::blockNav('foo.php');

        $navs = Nav::buildNavs();
        $this->assertSame('', $navs);
        Nav::unblockNav('foo.php');
    }

    public function testColoredHeadlineWithColoredNavItem(): void
    {
        Nav::addColoredHeadline('`!Section', false);
        Nav::add('`$Link', 'foo.php');

        $navs = Nav::buildNavs();
        $this->assertStringContainsString('colLtBlue', $navs);
        $this->assertStringContainsString('colLtRed', $navs);
        $this->assertStringContainsString('foo.php', $navs);
    }
}
