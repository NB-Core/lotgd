<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

final class NavColoredSubHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $nav, $template, $output;
        $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
        $nav = '';
        $output = new Output();
        $template = [
            'navhead' => '<span class="navhead">{title}</span>',
            'navheadsub' => '<span class="navheadsub">{title}</span>',
            'navitem' => '<a href="{link}">{text}</a>'
        ];
    }

    protected function tearDown(): void
    {
        global $session, $nav, $template, $output;
        unset($session, $nav, $template, $output);
    }

    public function testColoredSubHeaderRendersColors(): void
    {
        Nav::addHeader('Main', false);
        Nav::addColoredSubHeader('`!Sub');
        Nav::add('Link', 'foo.php');

        $navs = Nav::buildNavs();
        $this->assertStringContainsString("colLtBlue", $navs);
        $this->assertStringContainsString('</span>', $navs);
    }
}
