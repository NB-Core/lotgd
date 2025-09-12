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
final class DeleteAccountsLogFailureTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$mockResults = [];
        Database::$affected_rows = 0;
        Database::$last_error = '';
        Database::$instance = new class {
            public function query(string $sql): bool { return true; }
            public function affectedRows(): int { return \Lotgd\Tests\Stubs\Database::$affected_rows; }
            public function error(): string { return \Lotgd\Tests\Stubs\Database::$last_error; }
        };
        if (! class_exists('Lotgd\\GameLog', false)) {
            eval('namespace Lotgd; class GameLog { public static array $entries = []; public static function log(string $m, string $c, bool $f = false): void { self::$entries[] = [$c, $m]; } }');
        } else {
            \Lotgd\GameLog::$entries = [];
        }
    }

    public function testLogsOnFailure(): void
    {
        Database::$last_error = 'fk violation';

        $ref = new \ReflectionClass(ExpireChars::class);
        $method = $ref->getMethod('deleteAccounts');
        $method->setAccessible(true);
        $method->invoke(null, [1]);

        $this->assertSame([
            ['char deletion failure', 'Failed to delete account 1: fk violation'],
        ], \Lotgd\GameLog::$entries);
    }

    public function testNoLogOnSuccess(): void
    {
        \Lotgd\GameLog::$entries = [];
        Database::$affected_rows = 1;

        $ref = new \ReflectionClass(ExpireChars::class);
        $method = $ref->getMethod('deleteAccounts');
        $method->setAccessible(true);
        $method->invoke(null, [1]);

        $this->assertSame([], \Lotgd\GameLog::$entries);
    }
}
