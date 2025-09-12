<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CharCleanupDeletesAccountTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
    }

    public function testExpireCharsDeletesOnCleanupSuccess(): void
    {
        if (! class_exists('Lotgd\\Settings', false)) {
            eval('namespace Lotgd; class Settings { public function __construct(string $t = "settings_extended"){} public static function getInstance(): self { return new self(); } public function getSetting(string $n, mixed $d = null): mixed { return $d; } public function saveSetting(string $n, mixed $v): void {} }');
        }
        if (! class_exists('Lotgd\\PlayerFunctions', false)) {
            eval('namespace Lotgd; class PlayerFunctions { public static function charCleanup(int $id, int $type): bool { return true; } }');
        }
        if (! class_exists('Lotgd\\GameLog', false)) {
            eval('namespace Lotgd; class GameLog { public static function log(string $m, string $c): void {} }');
        }

        Database::$mockResults = [
            [["acctid" => 1, "login" => "test", "dragonkills" => 0, "level" => 1]],
        ];
        Database::$affected_rows = 1;

        $ref = new \ReflectionClass(\Lotgd\ExpireChars::class);
        $method = $ref->getMethod('cleanupExpiredAccounts');
        $method->setAccessible(true);
        $method->invoke(null);

        $this->assertStringContainsString('START TRANSACTION', Database::$queries[1] ?? '');
        $this->assertStringContainsString('DELETE FROM accounts WHERE acctid=1', Database::$queries[2] ?? '');
        $this->assertStringContainsString('COMMIT', Database::$queries[3] ?? '');
    }
}
