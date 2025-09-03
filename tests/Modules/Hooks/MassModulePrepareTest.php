<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Hooks;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @group hooks
 */
final class MassModulePrepareTest extends TestCase
{
    public function testMassModulePrepareDelegatesHooks(): void
    {
        require __DIR__ . '/../../Stubs/MassModulePrepareFunctions.php';
        \Lotgd\Modules\HookHandler::$received = [];
        \Lotgd\Modules\HookHandler::$calls    = 0;

        $hooks  = ['first', 'second'];
        $result = mass_module_prepare($hooks);

        $this->assertTrue($result);
        $this->assertSame([$hooks], \Lotgd\Modules\HookHandler::$received);
        $this->assertSame(1, \Lotgd\Modules\HookHandler::$calls);
    }

    public function testMassModulePrepareWithEmptyHooksReturnsTrue(): void
    {
        require __DIR__ . '/../../Stubs/MassModulePrepareFunctions.php';
        \Lotgd\Modules\HookHandler::$received = [];
        \Lotgd\Modules\HookHandler::$calls    = 0;

        $result = mass_module_prepare([]);

        $this->assertTrue($result);
        $this->assertSame(0, \Lotgd\Modules\HookHandler::$calls);
    }
}
