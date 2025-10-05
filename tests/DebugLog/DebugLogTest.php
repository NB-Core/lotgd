<?php

declare(strict_types=1);

namespace Lotgd\Tests\DebugLog;

use Lotgd\DebugLog;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

final class DebugLogTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();
        global $session;
        $session['user']['acctid'] = 1001;
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    public function testMessageWithQuotesAndBackslashesUsesBoundParameters(): void
    {
        $message = 'Quotes "double" and backslash \\ with single \'single\'';
        $field = 'special_field';
        $value = 7;
        $selectAfter = date('Y-m-d 00:00:00');

        DebugLog::add($message, target: 42, user: 77, field: $field, value: $value, consolidate: true);

        $connection = Database::getDoctrineConnection();
        $this->assertInstanceOf(DoctrineConnection::class, $connection);

        $this->assertSame([
            'actor' => 77,
            'field' => $field,
            'after' => $selectAfter,
        ], $connection->lastFetchAssociativeParams);

        $insert = $connection->executeStatements[0] ?? null;
        $this->assertNotNull($insert, 'Expected INSERT statement to be recorded');
        $this->assertFalse(str_contains($insert['sql'], $message), 'Message should not be interpolated into SQL');

        $expectedMessage = $message . " ({$value})";
        $this->assertSame($expectedMessage, $insert['params']['message']);
        $this->assertSame($field, $insert['params']['field']);
        $this->assertSame(77, $insert['params']['actor']);
        $this->assertSame(42, $insert['params']['target']);
        $this->assertSame($value, $insert['params']['value']);
    }

    public function testConsolidatedLogUpdatesExistingRow(): void
    {
        $existingMessage = 'Existing message';
        $coreValue = 5;
        $connection = Database::getDoctrineConnection();
        $connection->fetchAssociativeResults[] = [
            'id' => 12,
            'value' => 3,
            'message' => $existingMessage,
        ];

        DebugLog::add('New message should reuse existing', target: 10, user: 77, field: 'special_field', value: $coreValue, consolidate: true);

        $update = $connection->executeStatements[0] ?? null;
        $this->assertNotNull($update, 'Expected UPDATE statement to be recorded');
        $this->assertSame(12, $update['params']['id']);
        $this->assertSame($existingMessage . " ({$coreValue})", $update['params']['message']);
        $this->assertSame(3 + $coreValue, $update['params']['value']);
    }
}
