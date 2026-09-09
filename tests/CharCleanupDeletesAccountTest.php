<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\Database as CoreDatabase;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class CharCleanupDeletesAccountTest extends TestCase
{
    protected function setUp(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
        CoreDatabase::resetDoctrineConnection();
        $connection = CoreDatabase::getDoctrineConnection();
        $connection->queries = [];
        $connection->executeStatementResults = [];
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
            eval('namespace Lotgd; class GameLog { const SEVERITY_INFO = "info"; const SEVERITY_WARNING = "warning"; const SEVERITY_ERROR = "error"; const SEVERITY_DEBUG = "debug"; const CATEGORY_GENERAL = "general"; const CATEGORY_SECURITY = "security"; const CATEGORY_MAINTENANCE = "maintenance"; const CATEGORY_EXPIRATION = "expiration"; const CATEGORY_MODULES = "modules"; const CATEGORY_USERS = "user management"; const CATEGORY_SETTINGS = "settings"; const CATEGORY_CLAN = "clan"; const CATEGORY_BATTLE = "battle"; const CATEGORY_CACHE = "cache"; public static function log(string $m, string $c, bool $f = false, ?int $a = null, string $s = "info"): void {} }');
        }

        Database::$mockResults = [
            [["acctid" => 1, "login" => "test", "dragonkills" => 0, "level" => 1]],
        ];

        \Lotgd\ExpireChars::cleanupExpiredAccountsForTests();

        $queries = CoreDatabase::getDoctrineConnection()->queries;
        $this->assertStringContainsString('START TRANSACTION', $queries[1] ?? '');
        $this->assertStringContainsString('DELETE FROM accounts WHERE acctid = :acctid', $queries[2] ?? '');
        $this->assertStringContainsString('COMMIT', $queries[3] ?? '');
    }
}
