<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DataCache;
use Lotgd\Output;
use Lotgd\PageParts;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CharStatsEmptySessionTest extends TestCase
{
    public function testCharStatsHandlesEmptySession(): void
    {
        global $session, $settings, $output, $modulehook_returns;
        $modulehook_returns = [
            'onlinecharlist' => ['handled' => true, 'count' => 0, 'list' => ''],
        ];
        if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
            eval('namespace Lotgd\\Modules; class HookHandler { public static function hook($name, $data = [], $allowinactive = false, $only = false) { global $modulehook_returns; return $modulehook_returns[$name] ?? $data; } }');
        }
        class_exists(Database::class);
        class_exists(DataCache::class);
        $session = [];
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

        $outputString = PageParts::charStats();
        $this->assertIsString($outputString);
        $this->assertArrayNotHasKey('user', $session);
    }
}
