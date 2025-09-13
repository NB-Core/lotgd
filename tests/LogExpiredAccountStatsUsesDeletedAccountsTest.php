<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ExpireChars;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LogExpiredAccountStatsUsesDeletedAccountsTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
        Database::$affected_rows = 1;

        if (! class_exists('Lotgd\\Settings', false)) {
            eval('namespace Lotgd; class Settings { public function __construct(string $t = "settings_extended"){} public static function getInstance(): self { return new self(); } public function getSetting(string $n, mixed $d = null): mixed { return $d; } public function saveSetting(string $n, mixed $v): void {} }');
        }

        if (! class_exists('Lotgd\\PlayerFunctions', false)) {
            eval('namespace Lotgd; class PlayerFunctions { public static function charCleanup(int $id, int $type): bool { return $id === 1; } }');
        }

        if (! class_exists('Lotgd\\GameLog', false)) {
            eval('namespace Lotgd; class GameLog { public static array $entries = []; public static function log(string $m, string $c, bool $f = false, ?int $a = null): void { self::$entries[] = [$c, $m]; } }');
        } else {
            \Lotgd\GameLog::$entries = [];
        }
    }

    public function testLogsOnlyDeletedAccounts(): void
    {
        Database::$mockResults = [[
            ['acctid' => 1, 'login' => 'foo', 'dragonkills' => 0, 'level' => 1],
            ['acctid' => 2, 'login' => 'bar', 'dragonkills' => 1, 'level' => 2],
        ]];

        $ref = new \ReflectionClass(ExpireChars::class);
        $method = $ref->getMethod('cleanupExpiredAccounts');
        $method->setAccessible(true);
        $method->invoke(null);

        $summary = array_values(array_filter(\Lotgd\GameLog::$entries, fn($entry) => str_contains($entry[1], 'accounts:')));

        $this->assertCount(1, $summary);
        $this->assertStringContainsString('Deleted 1 accounts:', $summary[0][1]);
        $this->assertStringContainsString('foo:dk0-lv1', $summary[0][1]);
        $this->assertStringNotContainsString('bar:dk1-lv2', $summary[0][1]);
    }
}
