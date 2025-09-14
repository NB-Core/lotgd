<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CharCleanupFailurePreventsDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
    }

    public function testExpireCharsDoesNotDeleteOnCleanupFailure(): void
    {
        if (! class_exists('Lotgd\\Settings', false)) {
            eval('namespace Lotgd; class Settings { public function __construct(string $t = "settings_extended"){} public static function getInstance(): self { return new self(); } public function getSetting(string $n, mixed $d = null): mixed { return $d; } public function saveSetting(string $n, mixed $v): void {} }');
        }
        if (! class_exists('Lotgd\\PlayerFunctions', false)) {
            eval('namespace Lotgd; class PlayerFunctions { public static function charCleanup(int $id, int $type): bool { return false; } }');
        }
        if (! class_exists('Lotgd\\GameLog', false)) {
            eval('namespace Lotgd; class GameLog { public static function log(string $m, string $c, bool $f = false, ?int $a = null): void {} }');
        }

        Database::$mockResults = [
            [["acctid" => 1, "login" => "test", "dragonkills" => 0, "level" => 1]],
        ];

        $ref = new \ReflectionClass(\Lotgd\ExpireChars::class);
        $method = $ref->getMethod('cleanupExpiredAccounts');
        $method->setAccessible(true);
        $method->invoke(null);

        foreach (Database::$queries as $query) {
            $this->assertStringNotContainsString('DELETE FROM accounts', $query);
        }
        $this->assertContains('ROLLBACK', Database::$queries);
        $this->assertNotContains('COMMIT', Database::$queries);
    }

    public function testUserDelAbortsOnCleanupFailure(): void
    {
        if (! class_exists('Lotgd\\PlayerFunctions', false)) {
            eval('namespace Lotgd; class PlayerFunctions { public static function charCleanup(int $id, int $type): bool { return false; } }');
        }
        if (! class_exists('Lotgd\\AddNews', false)) {
            eval('namespace Lotgd; class AddNews { public static function add(string $m, string $n, bool $b = true): void {} }');
        }
        if (! function_exists('debuglog')) {
            eval('function debuglog(string $m): void {}');
        }

        global $session, $userid, $output;
        $session = ['user' => ['superuser' => 0]];
        $userid = 1;
        $output = new class {
            public array $log = [];
            public function output(string $m): void
            {
                $this->log[] = $m;
            }
        };

        Database::$mockResults = [
            [["name" => "Tester", "superuser" => 0]],
        ];

        include __DIR__ . '/../pages/user/user_del.php';

        $this->assertCount(1, Database::$queries);
        $this->assertStringStartsWith('SELECT name,superuser from accounts', Database::$queries[0]);
    }
}
