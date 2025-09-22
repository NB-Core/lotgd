<?php

declare(strict_types=1);

namespace Lotgd\Modules {
    class Installer
    {
        public static array $statusReturn = [];
        public static bool $installShouldSucceed = false;
        public static bool $uninstallShouldSucceed = false;
        public static bool $activateShouldSucceed = false;
        public static bool $deactivateShouldSucceed = false;
        public static bool $forceUninstallShouldSucceed = false;
        public static function getInstallStatus(bool $withDb = true): array
        {
            return self::$statusReturn;
        }
        public static function install(string $module): bool
        {
            return self::$installShouldSucceed;
        }
        public static function uninstall(string $module): bool
        {
            return self::$uninstallShouldSucceed;
        }
        public static function activate(string $module): bool
        {
            return self::$activateShouldSucceed;
        }
        public static function deactivate(string $module): bool
        {
            return self::$deactivateShouldSucceed;
        }
        public static function forceUninstall(string $module): bool
        {
            return self::$forceUninstallShouldSucceed;
        }
        public static function reset(): void
        {
            self::$statusReturn = [];
            self::$installShouldSucceed = false;
            self::$uninstallShouldSucceed = false;
            self::$activateShouldSucceed = false;
            self::$deactivateShouldSucceed = false;
            self::$forceUninstallShouldSucceed = false;
        }
    }
}

namespace Lotgd\Tests {

    use Lotgd\ModuleManager;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DummySettings;
    use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
    final class ModuleManagerTest extends TestCase
    {
        private string $cacheDir = '';

        protected function setUp(): void
        {
            class_exists(Database::class);
            \Lotgd\Modules\Installer::reset();
            \Lotgd\MySQL\Database::$lastSql = '';
            \Lotgd\MySQL\Database::$queries = [];

            $this->cacheDir = sys_get_temp_dir() . '/lotgd_module_manager_' . uniqid('', true);
            mkdir($this->cacheDir, 0700, true);

            $settings = new DummySettings([
                'usedatacache'  => 1,
                'datacachepath' => $this->cacheDir,
            ]);

            \Lotgd\Settings::setInstance($settings);
            $GLOBALS['settings'] = $settings;

            $this->resetDataCache();

            \Lotgd\DataCache::getInstance();
            \Lotgd\DataCache::getInstance()->updatedatacache('seed', ['primed' => true]);
            \Lotgd\DataCache::getInstance()->invalidatedatacache('seed');
            $this->clearCacheDir();

            $GLOBALS['session']['user']['acctid'] = 42;
        }

        protected function tearDown(): void
        {
            $this->clearCacheDir();
            if (isset($this->cacheDir) && is_dir($this->cacheDir)) {
                @rmdir($this->cacheDir);
            }

            unset($GLOBALS['settings'], $GLOBALS['session']);
            \Lotgd\Settings::setInstance(null);

            $this->resetDataCache();

            \Lotgd\Modules\Installer::reset();
            \Lotgd\MySQL\Database::$queries = [];
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

        public function testInstallReturnsFalseWhenInstallerFails(): void
        {
            $this->assertFalse(ModuleManager::install('mod'));
        }

        public function testInstallSuccessClearsCachesAndLogs(): void
        {
            $paths = $this->primeCaches(['hook-alpha', 'module-prepare-beta']);
            \Lotgd\Modules\Installer::$installShouldSucceed = true;

            $this->assertTrue(ModuleManager::install('mod'));

            $this->assertCachesRemoved($paths);
            $this->assertGamelogEntryContains("Module mod installed");
        }

        public function testUninstallReturnsFalseWhenInstallerFails(): void
        {
            $this->assertFalse(ModuleManager::uninstall('mod'));
        }

        public function testUninstallSuccessClearsCachesAndLogs(): void
        {
            $paths = $this->primeCaches(['hook-alpha', 'module-prepare-beta', 'inject-mod']);
            \Lotgd\Modules\Installer::$uninstallShouldSucceed = true;

            $this->assertTrue(ModuleManager::uninstall('mod'));

            $this->assertCachesRemoved($paths);
            $this->assertGamelogEntryContains("Module mod uninstalled");
        }

        public function testActivateReturnsFalseWhenInstallerFails(): void
        {
            $this->assertFalse(ModuleManager::activate('mod'));
        }

        public function testActivateSuccessClearsCachesAndLogs(): void
        {
            $paths = $this->primeCaches(['hook-alpha', 'module-prepare-beta', 'inject-mod']);
            \Lotgd\Modules\Installer::$activateShouldSucceed = true;

            $this->assertTrue(ModuleManager::activate('mod'));

            $this->assertCachesRemoved($paths);
            $this->assertGamelogEntryContains("Module mod activated");
        }

        public function testDeactivateReturnsFalseWhenInstallerFails(): void
        {
            $this->assertFalse(ModuleManager::deactivate('mod'));
        }

        public function testDeactivateSuccessClearsCachesAndLogs(): void
        {
            $paths = $this->primeCaches(['module-prepare-beta', 'inject-mod']);
            \Lotgd\Modules\Installer::$deactivateShouldSucceed = true;

            $this->assertTrue(ModuleManager::deactivate('mod'));

            $this->assertCachesRemoved($paths);
            $this->assertGamelogEntryContains("Module mod deactivated");
        }

        public function testReinstallUpdatesDate(): void
        {
            $paths = $this->primeCaches(['hook-alpha', 'module-prepare-beta', 'inject-mod']);

            ModuleManager::reinstall('mod');
            $expected = "UPDATE modules SET filemoddate='" . DATETIME_DATEMIN . "' WHERE modulename='mod'";
            $this->assertContains($expected, \Lotgd\MySQL\Database::$queries);
            $this->assertCachesRemoved($paths);
            $this->assertGamelogEntryContains('Module mod reinstalled');
        }

        public function testForceUninstallReturnsFalseWhenInstallerFails(): void
        {
            $paths = $this->primeCaches(['hook-alpha', 'module-prepare-beta', 'inject-mod']);

            $this->assertFalse(ModuleManager::forceUninstall('mod'));

            foreach ($paths as $path) {
                $this->assertFileExists($path);
            }

            $expectedPaths = array_values($paths);
            sort($expectedPaths);

            $this->assertSame($expectedPaths, $this->listCacheFiles());

            $needle = "INSERT INTO gamelog (message,category,filed,date,who) VALUES ('Module mod force-uninstalled','modules','0'";
            $matches = array_filter(
                \Lotgd\MySQL\Database::$queries,
                static fn (string $query): bool => str_contains($query, $needle)
            );

            $this->assertEmpty($matches, 'Unexpected gamelog entry was written.');
        }

        public function testForceUninstallClearsCachesAndLogs(): void
        {
            $paths = $this->primeCaches(['hook-alpha', 'module-prepare-beta', 'inject-mod']);
            \Lotgd\Modules\Installer::$forceUninstallShouldSucceed = true;

            $this->assertTrue(ModuleManager::forceUninstall('mod'));

            $this->assertCachesRemoved($paths);
            $this->assertGamelogEntryContains('Module mod force-uninstalled');
        }

        /**
         * @param list<string> $names
         * @return array<string,string>
         */
        private function primeCaches(array $names): array
        {
            $cache = \Lotgd\DataCache::getInstance();
            $paths = [];

            foreach ($names as $name) {
                $this->assertTrue($cache->updatedatacache($name, ['name' => $name]));
                $path = $cache->makecachetempname($name);
                $this->assertFileExists($path);
                $paths[$name] = $path;
            }

            return $paths;
        }

        /**
         * @param array<string,string> $paths
         */
        private function assertCachesRemoved(array $paths): void
        {
            foreach ($paths as $path) {
                $this->assertFileDoesNotExist($path);
            }

            $this->assertSame([], $this->listCacheFiles());
        }

        private function listCacheFiles(): array
        {
            $files = glob($this->cacheDir . '/*') ?: [];
            sort($files);

            return array_values(array_filter($files, 'is_file'));
        }

        private function assertGamelogEntryContains(string $message): void
        {
            $needle = "INSERT INTO gamelog (message,category,filed,date,who) VALUES ('{$message}','modules','0'";
            $matches = array_filter(
                \Lotgd\MySQL\Database::$queries,
                static fn (string $query): bool => str_contains($query, $needle)
            );

            $this->assertNotEmpty($matches, 'Expected gamelog entry was not written.');
        }

        private function clearCacheDir(): void
        {
            if (! isset($this->cacheDir) || ! is_dir($this->cacheDir)) {
                return;
            }

            foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        private function resetDataCache(): void
        {
            $reflection = new \ReflectionClass(\Lotgd\DataCache::class);
            foreach (['instance' => null, 'cache' => [], 'path' => '', 'checkedOld' => false] as $property => $value) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue(null, $value);
            }
        }
    }

}
