<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

final class NavigationSubSectionTest extends TestCase
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

    public function testSubHeadlineOutput(): void
    {
        Nav::addHeader('Main', false);
        Nav::addSubHeader('Sub');
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();
        $this->assertStringContainsString('<span class="navheadsub">Sub</span>', $navs);
        $this->assertStringContainsString('foo.php', $navs);
    }
}
