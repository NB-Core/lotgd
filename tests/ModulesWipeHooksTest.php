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
        ModuleManager::setMostRecentModule('mymodule');
    }

    public function testWipeHooksRemovesEventHooks(): void
    {
        Modules::wipeHooks();
        $this->assertStringContainsString('module_event_hooks', \Lotgd\MySQL\Database::$lastSql);
        $this->assertStringContainsString("modulename='mymodule'", \Lotgd\MySQL\Database::$lastSql);
    }
}
