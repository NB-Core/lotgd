<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Modules;
use Lotgd\Modules\ModuleManager;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class ModulesWipeHooksTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$lastSql = '';
        // Reset the Doctrine connection so statement logs from previous tests
        // don't leak into assertions that inspect executeStatements entries.
        \Lotgd\MySQL\Database::resetDoctrineConnection();
        ModuleManager::setMostRecentModule('mymodule');
    }

    public function testWipeHooksRemovesEventHooks(): void
    {
        Modules::wipeHooks();
        $connection = \Lotgd\MySQL\Database::getDoctrineConnection();
        $this->assertNotEmpty($connection->executeStatements);
        $lastStatement = $connection->executeStatements[array_key_last($connection->executeStatements)];
        $this->assertStringContainsString('module_event_hooks', $lastStatement['sql']);
        $this->assertSame('mymodule', $lastStatement['params']['module']);
    }
}
