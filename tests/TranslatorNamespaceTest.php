<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Translator;
use Lotgd\Sanitize;
use Lotgd\DataCache;
use Lotgd\Tests\Stubs\CacheDummySettings;
use PHPUnit\Framework\TestCase;

final class TranslatorNamespaceTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/lotgd_cache_' . uniqid();
        mkdir($this->cacheDir, 0700, true);
        $GLOBALS['settings'] = new CacheDummySettings([
            'datacachepath' => $this->cacheDir,
            'usedatacache'  => 1,
        ]);
        \Lotgd\MySQL\Database::$lastCacheName = '';
        \Lotgd\DataCache::getInstance()->massinvalidate();
        $GLOBALS['session'] = [];
        if (!defined('LANGUAGE')) {
            define('LANGUAGE', 'en');
        }
        $GLOBALS['language'] = 'en';
        if (!defined('DB_CHOSEN')) {
            define('DB_CHOSEN', true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['language']);
    }

    public function testLongNamespaceUsesShortCacheKey(): void
    {
        $longNamespace = str_repeat('a', 500);
        Translator::translateLoadNamespace($longNamespace, 'en');
        $cacheName = \Lotgd\MySQL\Database::$lastCacheName;
        $expectedNamespace = strlen($longNamespace) > Sanitize::URI_MAX_LENGTH
            ? sha1($longNamespace)
            : $longNamespace;
        $expected = 'translations-' . $expectedNamespace . '-en';
        $this->assertSame($expected, $cacheName);
        $cacheFile = DataCache::getInstance()->makecachetempname($cacheName);
        $this->assertLessThan(255, strlen($cacheFile));
        $this->assertTrue(DataCache::getInstance()->updatedatacache($cacheName, ['ok' => true]));
        $this->assertFileExists($cacheFile);
    }
}
