<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PageParts;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class AssembleMailLinkTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queryCacheResults = [];
    }

    protected function tearDown(): void
    {
        global $session, $settings;
        unset($session, $settings);
        Settings::setInstance(null);
        Database::$queryCacheResults = [];
    }

    public function testWithoutAjax(): void
    {
        global $session, $settings;
        $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'prefs' => []]];
        $settings = new DummySettings(['ajax' => 0]);
        Settings::setInstance($settings);
        Database::$queryCacheResults['mail-1'] = [['seencount' => 0, 'notseen' => 0]];
        [$header] = PageParts::assembleMailLink('{mail}', '');
        $this->assertStringContainsString("<a href='mail.php'", $header);
        $this->assertStringNotContainsString("<div id='maillink'>", $header);
    }

    public function testWithAjaxTheLinkCarriesTheIdThePollReplaces(): void
    {
        global $session, $settings;
        $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'prefs' => ['ajax' => 1]]];
        $settings = new DummySettings(['ajax' => 1]);
        Settings::setInstance($settings);
        Database::$queryCacheResults['mail-1'] = [['seencount' => 0, 'notseen' => 0]];
        [$header] = PageParts::assembleMailLink('{mail}', '');
        $this->assertStringStartsWith("<div id='maillink'><a href='mail.php'", $header);
        $this->assertStringEndsWith('</div>', $header);
        $this->assertStringNotContainsString('<script', $header, 'The async client takes its values from <meta name="lotgd-async">.');
    }
}
