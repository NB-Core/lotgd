<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\GameLog;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class GameLogSeverityTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$tablePrefix = '';
        global $session;
        $session['user']['acctid'] = 99;
    }

    protected function tearDown(): void
    {
        Database::$queries = [];
        unset($GLOBALS['session']);
    }

    public function testInvalidSeverityFallsBackToInfo(): void
    {
        GameLog::log('Default severity', 'test', false, null, 'invalid');
        $query = Database::$queries[0] ?? '';
        $this->assertStringContainsString("INSERT INTO gamelog (message,category,severity,filed,date,who) VALUES ('Default severity','test','info'", $query);
        $this->assertStringContainsString("'99')", $query);
    }

    public function testExplicitSeverityIsRecorded(): void
    {
        Database::$queries = [];
        GameLog::log('Error severity', 'test', false, 5, 'error');
        $query = Database::$queries[0] ?? '';
        $this->assertStringContainsString("'Error severity','test','error'", $query);
        $this->assertStringContainsString("'5')", $query);
    }
}
