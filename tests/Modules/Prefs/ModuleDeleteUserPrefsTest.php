<?php

declare(strict_types=1);

namespace {
    if (!function_exists('module_delete_userprefs')) {
        function module_delete_userprefs(int $user): void
        {
            \Lotgd\Modules\HookHandler::deleteUserPrefs($user);
        }
    }
}

namespace Lotgd\Tests\Modules\Prefs {

    use Lotgd\DataCache;
    use Lotgd\Modules\ModuleManager;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use Lotgd\Tests\Stubs\DummySettings;
    use PHPUnit\Framework\TestCase;

/**
 * @group prefs
 */
    final class ModuleDeleteUserPrefsTest extends TestCase
    {
        private string $tempDir;

        protected function setUp(): void
        {
            parent::setUp();

            $this->resetDataCacheState();

            $this->tempDir = sys_get_temp_dir() . '/lotgd-module-delete-user-prefs-' . uniqid('', true);
            if (!mkdir($this->tempDir) && !is_dir($this->tempDir)) {
                self::fail(sprintf('Unable to create temporary data cache directory: %s', $this->tempDir));
            }

            \Lotgd\Settings::setInstance(null);
            $GLOBALS['settings'] = new DummySettings([
                'usedatacache' => 1,
                'datacachepath' => $this->tempDir,
            ]);
            \Lotgd\Settings::setInstance($GLOBALS['settings']);

            class_exists(Database::class);

            $conn = new DoctrineConnection();
            Database::$doctrineConnection = $conn;
            \Lotgd\Doctrine\Bootstrap::$conn = $conn;

            global $session, $massinvalidates;
            $session = ['user' => ['acctid' => 1, 'loggedin' => true]];
            ModuleManager::setPrefs([]);
            $massinvalidates = [];
        }

        protected function tearDown(): void
        {
            $this->removeTempDir();
            $this->resetDataCacheState();
            unset($GLOBALS['settings']);
            \Lotgd\Settings::setInstance(null);

            Database::$doctrineConnection = null;
            \Lotgd\Doctrine\Bootstrap::$conn = null;

            parent::tearDown();
        }

        public function testDeleteUserPrefsClearsGlobalCache(): void
        {
            global $massinvalidates;

            $userId = 1;
            ModuleManager::setPrefs([
            $userId => [
                'modA' => ['prefA' => 'value'],
                'modB' => ['prefB' => 'value'],
            ],
            2 => [
                'modC' => ['prefC' => 'value'],
            ],
            ]);

            $cacheFile = $this->tempDir . '/' . sprintf('%smodule_userprefs-%d-test', \DATACACHE_FILENAME_PREFIX, $userId);
            file_put_contents($cacheFile, 'dummy');
            self::assertFileExists($cacheFile);

            module_delete_userprefs($userId);

            $prefs = ModuleManager::prefs();
            self::assertArrayNotHasKey($userId, $prefs);
            self::assertArrayHasKey(2, $prefs);
            self::assertFileDoesNotExist($cacheFile);
        }

        public function testDeletingWithEmptyPrefsDoesNothing(): void
        {
            $userId = 1;

            module_delete_userprefs($userId);

            self::assertSame([], ModuleManager::prefs());
        }

        private function resetDataCacheState(): void
        {
            $reflection = new \ReflectionClass(DataCache::class);

            foreach ([
                'instance' => null,
                'cache' => [],
                'path' => '',
                'checkedOld' => false,
            ] as $property => $value) {
                $propertyRef = $reflection->getProperty($property);
                $propertyRef->setAccessible(true);
                $propertyRef->setValue(null, $value);
            }
        }

        private function removeTempDir(): void
        {
            if (!isset($this->tempDir) || $this->tempDir === '' || !is_dir($this->tempDir)) {
                return;
            }

            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }

            @rmdir($this->tempDir);
            $this->tempDir = '';
        }
    }

}
