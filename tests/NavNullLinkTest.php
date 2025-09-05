<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

final class NavNullLinkTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $nav, $template, $output;
        $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
        $nav = '';
        $output = new Output();
        Nav::clearNav();
        $template = [
            'navitem' => '<a href="{link}"{accesskey}{popup}>{text}</a>',
            'navhelp' => '<span class="navhelp">{text}</span>',
        ];
    }

    protected function tearDown(): void
    {
        global $session, $nav, $template, $output;
        unset($session, $nav, $template, $output);
    }

    public function testNullLinkBehavesLikeEmptyString(): void
    {
        $expected = Nav::privateAddNav('Help', '');
        $actual = Nav::privateAddNav('Help', null);

        $this->assertSame($expected, $actual);
    }
}
