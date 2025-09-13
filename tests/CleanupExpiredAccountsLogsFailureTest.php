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
final class CleanupExpiredAccountsLogsFailureTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
        Database::$affected_rows = 0;

        if (! class_exists('Lotgd\\Settings', false)) {
            eval('namespace Lotgd; class Settings { public function __construct(string $t = "settings_extended"){} public static function getInstance(): self { return new self(); } public function getSetting(string $n, mixed $d = null): mixed { return $d; } public function saveSetting(string $n, mixed $v): void {} }');
        }

        if (! class_exists('Lotgd\\PlayerFunctions', false)) {
            eval('namespace Lotgd; class PlayerFunctions { public static function charCleanup(int $id, int $type): bool { return true; } }');
        }

        if (! class_exists('Lotgd\\GameLog', false)) {
            eval('namespace Lotgd; class GameLog { public static array $entries = []; public static function log(string $m, string $c, bool $f = false, ?int $a = null): void { self::$entries[] = [$c, $m]; } }');
        } else {
            \Lotgd\GameLog::$entries = [];
        }
    }

    public function testLogsOnDeleteFailure(): void
    {
        Database::$mockResults = [
            [["acctid" => 1, "login" => "test", "dragonkills" => 0, "level" => 1]],
            true,
            true,
            true,
        ];
        Database::$affected_rows = 0;

        $ref = new \ReflectionClass(ExpireChars::class);
        $method = $ref->getMethod('cleanupExpiredAccounts');
        $method->setAccessible(true);
        $method->invoke(null);

        $this->assertSame([
            ['char deletion failure', 'Failed to delete account 1: deletion failed'],
        ], \Lotgd\GameLog::$entries);

        $this->assertStringContainsString('ROLLBACK', Database::$queries[3] ?? '');
    }

    public function testLogsOnSuccess(): void
    {
        Database::$mockResults = [
            [["acctid" => 1, "login" => "test", "dragonkills" => 0, "level" => 1]],
            true,
            true,
            true,
        ];
        Database::$affected_rows = 1;

        $ref = new \ReflectionClass(ExpireChars::class);
        $method = $ref->getMethod('cleanupExpiredAccounts');
        $method->setAccessible(true);
        $method->invoke(null);

        $this->assertSame('char expiration', \Lotgd\GameLog::$entries[0][0] ?? null);
        $this->assertSame('Deleted account 1 (test)', \Lotgd\GameLog::$entries[0][1] ?? null);
        $this->assertCount(2, \Lotgd\GameLog::$entries);

        $this->assertStringContainsString('COMMIT', Database::$queries[3] ?? '');
    }
}
