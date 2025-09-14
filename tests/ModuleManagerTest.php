<?php

declare(strict_types=1);

namespace Lotgd\Modules {
    class Installer
    {
        public static array $statusReturn = [];
        public static function getInstallStatus(bool $withDb = true): array
        {
            return self::$statusReturn;
        }
        public static function install(string $module): bool
        {
            return false;
        }
        public static function uninstall(string $module): bool
        {
            return false;
        }
        public static function activate(string $module): bool
        {
            return false;
        }
        public static function deactivate(string $module): bool
        {
            return false;
        }
        public static function forceUninstall(string $module): bool
        {
            return true;
        }
    }
}

namespace Lotgd\Tests {

    use Lotgd\ModuleManager;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
    final class ModuleManagerTest extends TestCase
    {
        protected function setUp(): void
        {
            class_exists(Database::class);
            \Lotgd\DataCache::getInstance();
            \Lotgd\MySQL\Database::$lastSql = '';
        }

        public function testListInstalledBuildsSql(): void
        {
            ModuleManager::listInstalled();
            $this->assertStringContainsString('SELECT * FROM modules', \Lotgd\MySQL\Database::$lastSql);
        }

        public function testListUninstalledReturnsStatus(): void
        {
            \Lotgd\Modules\Installer::$statusReturn = ['uninstalledmodules' => ['x']];
            $this->assertSame(['x'], ModuleManager::listUninstalled());
        }

        public function testGetInstalledCategoriesUsesStatus(): void
        {
            \Lotgd\Modules\Installer::$statusReturn = ['installedcategories' => ['core' => 1]];
            $this->assertSame(['core' => 1], ModuleManager::getInstalledCategories());
        }

        public function testInstallReturnsTrue(): void
        {
            $this->assertFalse(ModuleManager::install('mod'));
        }

        public function testUninstallReturnsTrue(): void
        {
            $this->assertFalse(ModuleManager::uninstall('mod'));
        }

        public function testActivateReturnsTrue(): void
        {
            $this->assertFalse(ModuleManager::activate('mod'));
        }

        public function testDeactivateReturnsTrue(): void
        {
            $this->assertFalse(ModuleManager::deactivate('mod'));
        }

        public function testReinstallUpdatesDate(): void
        {
            ModuleManager::reinstall('mod');
            $expected = "UPDATE modules SET filemoddate='" . DATETIME_DATEMIN . "' WHERE modulename='mod'";
            $this->assertContains($expected, \Lotgd\MySQL\Database::$queries);
        }

        public function testForceUninstallReturnsTrue(): void
        {
            $this->assertTrue(ModuleManager::forceUninstall('mod'));
        }
    }

}
