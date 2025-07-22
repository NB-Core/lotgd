<?php

declare(strict_types=1);

namespace {
    if (!function_exists('install_module')) {
        function install_module(string $module): bool
        {
            $GLOBALS['install_called'][] = $module;
            return true;
        }
    }
    if (!function_exists('uninstall_module')) {
        function uninstall_module(string $module): bool
        {
            $GLOBALS['uninstall_called'][] = $module;
            return true;
        }
    }
    if (!function_exists('activate_module')) {
        function activate_module(string $module): bool
        {
            $GLOBALS['activate_called'][] = $module;
            return true;
        }
    }
    if (!function_exists('deactivate_module')) {
        function deactivate_module(string $module): bool
        {
            $GLOBALS['deactivate_called'][] = $module;
            return true;
        }
    }
    if (!function_exists('injectmodule')) {
        function injectmodule(string $module, bool $b): void
        {
            $GLOBALS['inject_called'][] = $module;
        }
    }
    if (!function_exists('invalidatedatacache')) {
        function invalidatedatacache(string $name): void
        {
            $GLOBALS['invalidates'][] = $name;
        }
    }
    if (!function_exists('massinvalidate')) {
        function massinvalidate(string $name): void
        {
            $GLOBALS['massinvalidates'][] = $name;
        }
    }
    if (!function_exists('get_module_install_status')) {
        function get_module_install_status(bool $with_db = true): array
        {
            return $GLOBALS['module_status'];
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\ModuleManager;

    require_once __DIR__ . '/../config/constants.php';

    final class ModuleManagerTest extends TestCase
    {
        protected function setUp(): void
        {
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
    }
}
