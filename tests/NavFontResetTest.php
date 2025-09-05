<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

final class NavFontResetTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $nav, $template, $output;
        $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
        $nav = '';
        $output = new Output();
        Nav::clearNav();
        $template = [
            'navitem' => '<a href="{link}">{text}</a>'
        ];
    }

    protected function tearDown(): void
    {
        global $session, $nav, $template, $output;
        unset($session, $nav, $template, $output);
    }

    public function testUnmatchedColorDoesNotInsertClosingSpanBeforeNextNav(): void
    {
        Nav::add('`!Red', 'red.php');
        Nav::add('Plain', 'plain.php');

        $navs = Nav::buildNavs();
        $this->assertStringNotContainsString('</span><a href="plain.php">', $navs);
    }

    public function testUnmatchedColorBeforeAnotherColorHasNoClosingSpan(): void
    {
        Nav::add('`!Red', 'red.php');
        Nav::add('`@Green', 'green.php');

        $navs = Nav::buildNavs();
        $this->assertStringNotContainsString('</span><span', $navs);
    }
}
