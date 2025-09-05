<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Nav\NavigationItem;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

final class NavigationItemTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $nav, $output;
        $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
        $nav = '';
        $output = new Output();
        Nav::clearNav();
        Template::getInstance()->setTemplate([
            'navitem' => '<a href="{link}"{accesskey}{popup}>{text}</a>'
        ]);
    }

    protected function tearDown(): void
    {
        global $session, $nav, $output;
        unset($session, $nav, $output);
        Template::getInstance()->setTemplate([]);
    }

    public function testRenderProducesLinkHtml(): void
    {
        $item = new NavigationItem('Home', 'index.php');
        $html = $item->render();
        $this->assertStringContainsString('index.php', $html);
        $this->assertStringContainsString('navhi', $html);
    }
}
