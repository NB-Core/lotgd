<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DateTime;
use Lotgd\Output;
use Lotgd\PageParts;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PagePartsOnlineListTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $settings, $output, $modulehook_returns;
        $modulehook_returns = [];
        if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
            eval('namespace Lotgd\\Modules; class HookHandler { public static function hook($name, $data = [], $allowinactive = false, $only = false) { global $modulehook_returns; return $modulehook_returns[$name] ?? $data; } }');
        }
        class_exists(Database::class);
        $session = ['loggedin' => false];
        $output = new Output();
        $settings = new DummySettings([
            'usedatacache' => 0,
            'LOGINTIMEOUT' => 900,
            'homeonline_mode' => 0,
            'homeonline_minutes' => 15,
            'enabletranslation' => false,
        ]);
        Database::$lastSql = '';
        Database::$instance = new class {
            public array $queries = [];
            public function query(string $sql)
            {
                $this->queries[] = $sql;
                return [];
            }
        };
    }

    protected function tearDown(): void
    {
        global $session, $settings, $output, $modulehook_returns;
        unset($session, $settings, $output);
        $modulehook_returns = [];
    }

    public function testMode0CurrentOnline(): void
    {
        global $settings;
        $settings->saveSetting('homeonline_mode', 0);
        $outputString = PageParts::charStats();
        $this->assertStringContainsString('loggedin=1', Database::$lastSql);
        $this->assertStringContainsString('Online Characters (0 players):', $outputString);
    }

    public function testMode1UsesTimeout(): void
    {
        global $settings;
        $settings->saveSetting('homeonline_mode', 1);
        $outputString = PageParts::charStats();
        $this->assertStringContainsString('loggedin=1', Database::$lastSql);
        $expected = 'Online Characters in the last ' . DateTime::readableTime(900, false) . ':';
        $this->assertStringContainsString($expected, $outputString);
    }

    public function testMode2UsesCustomMinutes(): void
    {
        global $settings;
        $settings->saveSetting('homeonline_mode', 2);
        $settings->saveSetting('homeonline_minutes', 30);
        $outputString = PageParts::charStats();
        $this->assertStringNotContainsString('loggedin=1', Database::$lastSql);

        $this->assertMatchesRegularExpression(
            "/laston>'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})'/",
            Database::$lastSql
        );
        preg_match("/laston>'([^']+)'/", Database::$lastSql, $matches);
        $actual = strtotime($matches[1]);
        $expected = strtotime('-30 minutes');
        $this->assertEqualsWithDelta($expected, $actual, 1);

        $this->assertStringContainsString('Online Characters last 30 minutes:', $outputString);
    }
}
