<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PageParts;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PagePartsPaypalBelowSlotTest extends TestCase
{
    protected function setUp(): void
    {
        global $modulehook_returns;
        $modulehook_returns = [];
        if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
            eval('namespace Lotgd\\Modules; class HookHandler { public static function hook($name, $data = [], $allowinactive = false, $only = false) { global $modulehook_returns; return $modulehook_returns[$name] ?? $data; } }');
        }
    }

    protected function tearDown(): void
    {
        global $modulehook_returns, $session;
        $modulehook_returns = [];
        unset($session);
    }

    public function testResolvePaypalBelowSlotNormalizesHookValue(): void
    {
        global $modulehook_returns;

        $modulehook_returns['paypal-below'] = ['paypal_below' => "  <strong>Donate</strong>  \n"];
        $this->assertSame('<strong>Donate</strong>', PageParts::resolvePaypalBelowSlot());

        $modulehook_returns['paypal-below'] = ['paypal_below' => ['invalid']];
        $this->assertSame('', PageParts::resolvePaypalBelowSlot());

        $modulehook_returns['paypal-below'] = 'invalid';
        $this->assertSame('', PageParts::resolvePaypalBelowSlot());
    }

    public function testBuildPaypalDonationMarkupAppendsPlaceholderWithoutEmptyRow(): void
    {
        global $session;
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['REQUEST_URI'] = '/village.php';
        $_SERVER['SERVER_NAME'] = 'example.test';
        $_SERVER['SERVER_PORT'] = '80';

        $session = [
            'user' => [
                'name' => 'Tester',
                'login' => 'tester',
                'loggedin' => false,
                'laston' => '1970-01-01 00:00:00',
            ],
        ];

        [$header] = PageParts::buildPaypalDonationMarkup(
            '{paypal}',
            '{paypal}',
            '',
            new DummySettings(['paypalemail' => '', 'charset' => 'UTF-8']),
            '2.0.0'
        );

        $this->assertStringContainsString('</table>{paypal_below}', $header);
        $this->assertStringNotContainsString('margin-top: 0.8em', $header);
        $this->assertStringNotContainsString('colspan="2"', $header);
    }
}
