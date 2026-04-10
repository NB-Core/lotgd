<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class NavAccessKeyHolidayRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $nav, $output;
        $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
        $nav = '';
        $output = new Output();
        Nav::clearNav();
        Template::getInstance()->setTemplate([
            'navitem' => '<a href="{link}"{accesskey}{popup}>{text}</a>',
        ]);
    }

    protected function tearDown(): void
    {
        global $session, $nav, $output;
        unset($session, $nav, $output);
        Template::getInstance()->setTemplate([]);
    }

    public function testExplicitKeyIsPreservedWhenHighlightIsImpossible(): void
    {
        $html = (string) Nav::privateAddNav('z?###', 'foo.php');

        self::assertStringContainsString('href="foo.php"', $html);
        self::assertStringContainsString('accesskey="z"', $html);
        self::assertArrayHasKey('z', Nav::getQuickKeys());
        self::assertStringNotContainsString('`Hz`H', $html);
    }

    public function testPreHolidayKeyCanExistEvenWhenRenderedTextCannotHighlightIt(): void
    {
        $containsMethod = new ReflectionMethod(Nav::class, 'containsAccessKeyChar');
        $containsMethod->setAccessible(true);
        $highlightMethod = new ReflectionMethod(Nav::class, 'highlightAccessKey');
        $highlightMethod->setAccessible(true);

        self::assertTrue($containsMethod->invoke(null, 'Alpha', 'A'));
        self::assertSame('lpha', $highlightMethod->invoke(null, 'lpha', 'A'));
    }

    public function testNoCrashWhenHighlightInsertionCannotBeApplied(): void
    {
        $html = (string) Nav::privateAddNav('q?---', 'bar.php');

        self::assertNotSame('', $html);
        self::assertArrayHasKey('q', Nav::getQuickKeys());
    }
}
