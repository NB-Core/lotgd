<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Injection;

use Lotgd\Modules;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

if (!function_exists(__NAMESPACE__ . '\\injectmodule')) {
    function injectmodule(string $moduleName, bool $force = false, bool $withDb = true): bool
    {
        global $testInjectedModules;

        $testInjectedModules ??= [];

        if (! $force && ($testInjectedModules[$moduleName] ?? false)) {
            return false;
        }

        $testInjectedModules[$moduleName] = true;

        return Modules::inject($moduleName, $force, $withDb);
    }
}

/**
 * @group injection
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class InjectModuleSuccessTest extends TestCase
{
    private string $moduleDir;
    private string $moduleFile;
    private string $origCwd;

    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$queryCacheResults = [];

        $this->origCwd   = getcwd();
        $this->moduleDir = sys_get_temp_dir() . '/lotgd_module_' . uniqid();
        mkdir($this->moduleDir . '/modules', 0777, true);

        $this->moduleFile = $this->moduleDir . '/modules/tempModule.php';
        $code = <<<'PHP'
<?php
function tempModule_getmoduleinfo(): array { return ['name' => 'Temp Module', 'version' => '1.0']; }
function tempModule_install(): bool { return true; }
function tempModule_uninstall(): bool { return true; }
PHP;
        file_put_contents($this->moduleFile, $code);

        $filemoddate = date('Y-m-d H:i:s', filemtime($this->moduleFile));
        \Lotgd\MySQL\Database::$queryCacheResults['inject-tempModule'] = [[
            'active' => 1,
            'filemoddate' => $filemoddate,
            'infokeys' => '|name|version|',
            'version' => '1.0',
        ]];

        $ref  = new \ReflectionClass(Modules::class);
        $prop = $ref->getProperty('injectedModules');
        $prop->setAccessible(true);
        $prop->setValue(null, [1 => [], 0 => []]);

        $GLOBALS['testInjectedModules'] = [];
    }

    protected function tearDown(): void
    {
        if (file_exists($this->moduleFile)) {
            unlink($this->moduleFile);
        }
        if (is_dir($this->moduleDir . '/modules')) {
            rmdir($this->moduleDir . '/modules');
            rmdir($this->moduleDir);
        }
        chdir($this->origCwd);
        \Lotgd\MySQL\Database::$queryCacheResults = [];
    }

    public function testInjectsModuleAndRegisters(): void
    {
        chdir($this->moduleDir);
        $result = injectmodule('tempModule');
        chdir($this->origCwd);

        $this->assertTrue($result);

        $ref  = new \ReflectionClass(Modules::class);
        $prop = $ref->getProperty('injectedModules');
        $prop->setAccessible(true);
        $injected = $prop->getValue();

        $this->assertArrayHasKey('tempModule', $injected[0]);
        $this->assertTrue($injected[0]['tempModule']);
    }

    public function testReinjectModuleWithForce(): void
    {
        chdir($this->moduleDir);

        $this->assertTrue(injectmodule('tempModule'));
        $this->assertFalse(injectmodule('tempModule'));
        $this->assertTrue(injectmodule('tempModule', true));

        chdir($this->origCwd);

        $ref  = new \ReflectionClass(Modules::class);
        $prop = $ref->getProperty('injectedModules');
        $prop->setAccessible(true);
        $injected = $prop->getValue();

        $this->assertArrayHasKey('tempModule', $injected[1]);
        $this->assertTrue($injected[1]['tempModule']);
    }

    public function testForceInjectionWithoutDbSkipsLookup(): void
    {
        chdir($this->moduleDir);

        injectmodule('tempModule');

        \Lotgd\MySQL\Database::$lastCacheName = '';
        \Lotgd\MySQL\Database::$queryCacheResults = [];

        $this->assertTrue(injectmodule('tempModule', true, false));

        chdir($this->origCwd);

        $this->assertSame('', \Lotgd\MySQL\Database::$lastCacheName);
    }
}
