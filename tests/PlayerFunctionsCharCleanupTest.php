<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PlayerFunctions;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PlayerFunctionsCharCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        global $modulehook_returns;
        $modulehook_returns = [];
        if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
            eval('namespace Lotgd\\Modules; class HookHandler { public static array $deleted = []; public static function hook($name, $data = [], $allowinactive = false, $only = false) { global $modulehook_returns; return $modulehook_returns[$name] ?? $data; } public static function deleteUserPrefs(int $id): void { self::$deleted[] = $id; }}');
        }
        class_exists(Database::class);
        Database::$queries = [];
        Database::$mockResults = [];
        if (! defined('CLAN_LEADER')) {
            define('CLAN_LEADER', 1);
        }
        if (! defined('CLAN_FOUNDER')) {
            define('CLAN_FOUNDER', 2);
        }
        if (! defined('CLAN_APPLICANT')) {
            define('CLAN_APPLICANT', 3);
        }
    }

    public function testCleanupPreventedByHook(): void
    {
        global $modulehook_returns;
        $modulehook_returns['delete_character'] = ['prevent_cleanup' => true];
        $result = PlayerFunctions::charCleanup(1, 0);
        $this->assertFalse($result);
        $this->assertSame([], \Lotgd\Modules\HookHandler::$deleted);
        $this->assertSame([], Database::$queries);
    }

    public function testCleanupRunsWhenNotPrevented(): void
    {
        global $modulehook_returns;
        $modulehook_returns['delete_character'] = [];
        Database::$mockResults = [true, true, [['clanrank' => 0, 'clanid' => 0]]];
        $result = PlayerFunctions::charCleanup(1, 0);
        $this->assertTrue($result);
        $this->assertSame([1], \Lotgd\Modules\HookHandler::$deleted);
        $this->assertCount(3, Database::$queries);
    }
}
