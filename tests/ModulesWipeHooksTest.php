<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Modules;
use Lotgd\DataCache;
use Lotgd\Modules\ModuleManager;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DoctrineResult;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

function modules_wipe_hooks_test_callback(string $hookname, array $args): array
{
    return $args + ['obsolete' => $hookname];
}

final class ModulesWipeHooksTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$lastSql = '';
        // Reset the Doctrine connection so statement logs from previous tests
        // don't leak into assertions that inspect executeStatements entries.
        \Lotgd\MySQL\Database::resetDoctrineConnection();
        Database::$queryCacheResults = [];
        Modules::resetState();
        ModuleManager::setMostRecentModule('mymodule');
        $this->cacheDir = sys_get_temp_dir() . '/lotgd_hook_cache_' . uniqid();
        mkdir($this->cacheDir, 0700, true);
        DataCache::resetState();
        $GLOBALS['settings'] = new DummySettings([
            'datacachepath' => $this->cacheDir,
            'usedatacache' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
        DataCache::resetState();
        Modules::resetState();
        unset($GLOBALS['settings']);
    }

    public function testWipeHooksRemovesEventHooks(): void
    {
        Modules::wipeHooks();
        $connection = \Lotgd\MySQL\Database::getDoctrineConnection();
        $this->assertNotEmpty($connection->executeStatements);
        $lastStatement = $connection->executeStatements[array_key_last($connection->executeStatements)];
        $this->assertStringContainsString('module_event_hooks', $lastStatement['sql']);
        $this->assertSame('mymodule', $lastStatement['params']['module']);
    }

    public function testWipeHooksInvalidatesHookCachesInMemoryAndOnDisk(): void
    {
        $keys = [
            'hook-index', 'hook-village',
            'module_prepare-' . md5('indexvillage'),
            'module_prepare-' . md5('village'),
            'event-forest-0', 'event-forest-1',
            'event-village-0', 'event-village-1',
        ];
        $cache = DataCache::getInstance();
        foreach ($keys as $key) {
            $this->assertTrue($cache->updatedatacache($key, [['modulename' => 'mymodule']]));
            $this->assertNotFalse($cache->datacache($key));
        }
        $this->assertTrue($cache->updatedatacache('modulesettings-other', ['value' => 'keep']));

        Modules::wipeHooks();

        foreach ($keys as $key) {
            $this->assertFalse($cache->datacache($key), 'Stale in-memory hook cache: ' . $key);
            $this->assertFileDoesNotExist($cache->makecachetempname($key));
        }
        $this->assertSame(['value' => 'keep'], $cache->datacache('modulesettings-other'));
        // A fresh request must not recover obsolete registrations from files.
        DataCache::resetState();
        foreach ($keys as $key) {
            $this->assertFalse(DataCache::getInstance()->datacache($key));
        }
        $this->assertSame(['value' => 'keep'], DataCache::getInstance()->datacache('modulesettings-other'));
    }

    public function testWipeHooksStopsDispatchingPreloadedObsoleteCallbacks(): void
    {
        // Hook preloading reads settings too; those are empty in this fixture.
        Database::setDoctrineConnection(new class extends DoctrineConnection {
            public function executeQuery(string $sql, array $params = [], array $types = []): DoctrineResult
            {
                return new DoctrineResult([]);
            }
        });
        $GLOBALS['session'] = ['user' => ['superuser' => 0]];
        $key = 'module_prepare-' . md5('index');
        Database::$queryCacheResults[$key] = [[
            'modulename' => 'mymodule', 'location' => 'index',
            'hook_callback' => __NAMESPACE__ . '\\modules_wipe_hooks_test_callback', 'whenactive' => '',
        ]];
        Modules::setInjectedModules([0 => ['mymodule' => true], 1 => []]);
        $this->assertTrue(Modules::massPrepare(['index']));
        $this->assertSame(['obsolete' => 'index'], Modules::hook('index', []));

        Modules::wipeHooks();
        Database::$queryCacheResults = [];

        $this->assertSame([], Modules::hook('index', []), 'Removed callback was dispatched from the request preload');
        // A second prepare must not append to stale per-request preload state.
        $this->assertTrue(Modules::massPrepare(['index']));
        $this->assertSame([], Modules::hook('index', []));
    }
}
