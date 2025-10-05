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
        Database::resetDoctrineConnection();
        global $session;
        $session['user']['acctid'] = 99;
    }

    protected function tearDown(): void
    {
        Database::$queries = [];
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    public function testInvalidSeverityFallsBackToInfo(): void
    {
        GameLog::log('Default severity', 'test', false, null, 'invalid');
        $conn   = Database::getDoctrineConnection();
        $record = $conn->executeStatements[0] ?? null;

        $this->assertNotNull($record);
        $this->assertStringContainsString('INSERT INTO gamelog (message,category,severity,filed,date,who) VALUES (:message, :category, :severity, :filed, :date, :who)', $record['sql']);
        $this->assertSame('info', $record['params']['severity']);
        $this->assertSame(99, $record['params']['who']);
    }

    public function testExplicitSeverityIsRecorded(): void
    {
        Database::$queries = [];
        GameLog::log('Error severity', 'test', false, 5, 'error');
        $conn   = Database::getDoctrineConnection();
        $record = $conn->executeStatements[0] ?? null;

        $this->assertNotNull($record);
        $this->assertSame('error', $record['params']['severity']);
        $this->assertSame(5, $record['params']['who']);
        $this->assertSame('Error severity', $record['params']['message']);
    }

    public function testQuotedAndMultibyteMessageIsPersisted(): void
    {
        $message = 'Quotes "double" and \'single\' with emoji 😃 and kanji 漢字';
        GameLog::log($message, 'special', true, 7, 'warning');

        $conn   = Database::getDoctrineConnection();
        $record = $conn->executeStatements[0] ?? null;

        $this->assertNotNull($record);
        $this->assertSame($message, $record['params']['message']);
        $this->assertSame('special', $record['params']['category']);
        $this->assertSame(1, $record['params']['filed']);
        $this->assertSame(7, $record['params']['who']);
        $this->assertSame('warning', $record['params']['severity']);
    }
}
