<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ModuleManager;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class ModuleManagerTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$lastSql = '';
        $GLOBALS['install_called'] = $GLOBALS['uninstall_called'] = [];
        $GLOBALS['activate_called'] = $GLOBALS['deactivate_called'] = [];
        $GLOBALS['inject_called'] = $GLOBALS['invalidates'] = $GLOBALS['massinvalidates'] = [];
        $GLOBALS['module_status'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['install_called'], $GLOBALS['uninstall_called'], $GLOBALS['activate_called'], $GLOBALS['deactivate_called'], $GLOBALS['inject_called'], $GLOBALS['invalidates'], $GLOBALS['massinvalidates'], $GLOBALS['module_status']);
    }

    public function testListInstalledBuildsSql(): void
    {
        ModuleManager::listInstalled();
        $this->assertStringContainsString('SELECT * FROM modules', \Lotgd\MySQL\Database::$lastSql);
    }

    public function testListUninstalledReturnsStatus(): void
    {
        $GLOBALS['module_status'] = ['uninstalledmodules' => ['x']];
        $this->assertSame(['x'], ModuleManager::listUninstalled());
    }

    public function testGetInstalledCategoriesUsesStatus(): void
    {
        $GLOBALS['module_status'] = ['installedcategories' => ['core' => 1]];
        $this->assertSame(['core' => 1], ModuleManager::getInstalledCategories());
    }

    public function testInstallReturnsTrue(): void
    {
        $this->assertTrue(ModuleManager::install('mod'));
    }

    public function testUninstallReturnsTrue(): void
    {
        $this->assertTrue(ModuleManager::uninstall('mod'));
    }

    public function testActivateReturnsTrue(): void
    {
        $this->assertTrue(ModuleManager::activate('mod'));
    }

    public function testDeactivateReturnsTrue(): void
    {
        $this->assertTrue(ModuleManager::deactivate('mod'));
    }

    public function testReinstallUpdatesDate(): void
    {
        ModuleManager::reinstall('mod');
        $this->assertStringContainsString("SET filemoddate='" . DATETIME_DATEMIN . "'", \Lotgd\MySQL\Database::$lastSql);
    }

    public function testForceUninstallReturnsTrue(): void
    {
        $this->assertTrue(ModuleManager::forceUninstall('mod'));
    }
}
