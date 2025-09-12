<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DataCache;
use Lotgd\Tests\Stubs\CacheDummySettings;
use PHPUnit\Framework\TestCase;

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
            $p->setValue(null, $val);
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

        $this->assertTrue(DataCache::getInstance()->updatedatacache($name, $data));
        $this->assertFileExists(DataCache::getInstance()->makecachetempname($name));

        $cached = DataCache::getInstance()->datacache($name);
        $this->assertSame($data, $cached);

        DataCache::getInstance()->invalidatedatacache($name);
        $this->assertFileDoesNotExist(DataCache::getInstance()->makecachetempname($name));
    }

    public function testMassInvalidate(): void
    {
        $prefix = 'pref';
        DataCache::getInstance()->updatedatacache($prefix . '1', [1]);
        DataCache::getInstance()->updatedatacache($prefix . '2', [2]);
        DataCache::getInstance()->updatedatacache('other', [3]);

        DataCache::getInstance()->massinvalidate($prefix);

        $this->assertFileDoesNotExist(DataCache::getInstance()->makecachetempname($prefix . '1'));
        $this->assertFileDoesNotExist(DataCache::getInstance()->makecachetempname($prefix . '2'));
        $this->assertFileExists(DataCache::getInstance()->makecachetempname('other'));
    }

    public function testUpdateCacheFailureOnBadPath(): void
    {
        $invalidPath = '/dev/null/' . uniqid();
        $GLOBALS['settings']->saveSetting('datacachepath', $invalidPath);

        $this->assertFalse(DataCache::getInstance()->updatedatacache('failpath', ['x' => 1]));
        $this->assertFileDoesNotExist(DataCache::getInstance()->makecachetempname('failpath'));
    }

    public function testUpdateCacheFailureWhenBasePathIsFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cachefile');
        $GLOBALS['settings']->saveSetting('datacachepath', $file);

        $this->assertFalse(DataCache::getInstance()->updatedatacache('filebase', ['x' => 1]));
        $this->assertFileDoesNotExist(DataCache::getInstance()->makecachetempname('filebase'));

        unlink($file);
    }

    public function testLongCacheKeyIsHashed(): void
    {
        $longKey = str_repeat('a', 250);
        $data = ['foo' => 'bar'];

        $this->assertTrue(DataCache::getInstance()->updatedatacache($longKey, $data));
        $expected = DATACACHE_FILENAME_PREFIX . substr($longKey, 0, 40) . '-' . sha1($longKey);
        $this->assertSame($expected, basename(DataCache::getInstance()->makecachetempname($longKey)));
        $this->assertSame($data, DataCache::getInstance()->datacache($longKey));
    }

    public function testUpdateCacheFailureOnJsonError(): void
    {
        $resource = tmpfile();
        $this->assertFalse(DataCache::getInstance()->updatedatacache('failjson', $resource));
        fclose($resource);
        $this->assertFileDoesNotExist(DataCache::getInstance()->makecachetempname('failjson'));
    }

    public function testCachePersistsForSpecifiedMinutes(): void
    {
        $name = 'persisted';
        $data = ['value' => 99];

        $minutes = 2;
        $seconds = $minutes * 60;

        $this->assertTrue(DataCache::getInstance()->updatedatacache($name, $data));

        $file = DataCache::getInstance()->makecachetempname($name);

        touch($file, time() - $seconds + 1);
        $this->assertSame($data, DataCache::getInstance()->datacache($name, $seconds));

        $ref = new \ReflectionClass(DataCache::class);
        $prop = $ref->getProperty('cache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        touch($file, time() - $seconds - 1);
        $this->assertFalse(DataCache::getInstance()->datacache($name, $seconds));
    }
}
