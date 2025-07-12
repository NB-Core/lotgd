<?php

declare(strict_types=1);

namespace Lotgd {
    if (!class_exists('Lotgd\\Settings', false)) {
        class Settings {
            public function __construct(string|false $table = false){}
            public function getSetting(string|int $name, mixed $default = false): mixed {
                return $GLOBALS['settings_array'][$name] ?? $default;
            }
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\DataCache;
    use Lotgd\Settings;

    require_once __DIR__ . '/../config/constants.php';

    if (!defined('DATACACHE_FILENAME_PREFIX')) {
        define('DATACACHE_FILENAME_PREFIX', 'datacache-');
    }

    if (!class_exists('CacheDummySettings')) {
        class CacheDummySettings extends Settings
        {
            private array $values;

            public function __construct(array $values = [])
            {
                $this->values = $values;
            }

            public function getSetting(string|int $name, mixed $default = false): mixed
            {
                return $this->values[$name] ?? $default;
            }

            public function loadSettings(): void {}
            public function clearSettings(): void {}
            public function saveSetting(string|int $name, mixed $value): bool
            {
                $this->values[$name] = $value;
                return true;
            }
            public function getArray(): array
            {
                return $this->values;
            }
        }
    }

    final class DataCacheTest extends TestCase
    {
        private string $cacheDir;

        protected function setUp(): void
        {
            $this->cacheDir = sys_get_temp_dir() . '/lotgd_cache_' . uniqid();
            mkdir($this->cacheDir, 0700, true);
            $ref = new \ReflectionClass(DataCache::class);
            foreach (['cache' => [], 'path' => '', 'checkedOld' => false] as $prop => $val) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($val);
            }
            $GLOBALS['settings'] = new CacheDummySettings([
                'datacachepath' => $this->cacheDir,
                'usedatacache'  => 1,
            ]);
        }

        protected function tearDown(): void
        {
            if (is_dir($this->cacheDir)) {
                foreach (glob($this->cacheDir . '/*') as $file) {
                    unlink($file);
                }
                rmdir($this->cacheDir);
            }
            unset($GLOBALS['settings']);
        }

        public function testCacheLifecycle(): void
        {
            $name = 'sample';
            $data = ['value' => 42];

            $this->assertTrue(DataCache::updatedatacache($name, $data));
            $this->assertFileExists(DataCache::makecachetempname($name));

            $cached = DataCache::datacache($name);
            $this->assertSame($data, $cached);

            DataCache::invalidatedatacache($name);
            $this->assertFileDoesNotExist(DataCache::makecachetempname($name));
        }

        public function testMassInvalidate(): void
        {
            $prefix = 'pref';
            DataCache::updatedatacache($prefix . '1', [1]);
            DataCache::updatedatacache($prefix . '2', [2]);
            DataCache::updatedatacache('other', [3]);

            DataCache::massinvalidate($prefix);

            $this->assertFileDoesNotExist(DataCache::makecachetempname($prefix . '1'));
            $this->assertFileDoesNotExist(DataCache::makecachetempname($prefix . '2'));
            $this->assertFileExists(DataCache::makecachetempname('other'));
        }
    }
}
