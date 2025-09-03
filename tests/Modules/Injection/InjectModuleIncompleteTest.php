<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Injection;

use Lotgd\Modules;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

if (!function_exists(__NAMESPACE__ . '\\injectmodule')) {
    function injectmodule(string $moduleName): bool
    {
        return Modules::inject($moduleName);
    }
}

/**
 * @group injection
 */
final class InjectModuleIncompleteTest extends TestCase
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

        $this->moduleFile = $this->moduleDir . '/modules/badModule.php';
        $code = <<<'PHP'
<?php
function badModule_getmoduleinfo(): array { return ['name' => 'Bad Module', 'version' => '1.0']; }
PHP;
        file_put_contents($this->moduleFile, $code);

        $filemoddate = date('Y-m-d H:i:s', filemtime($this->moduleFile));
        \Lotgd\MySQL\Database::$queryCacheResults['inject-badModule'] = [[
            'active' => 1,
            'filemoddate' => $filemoddate,
            'infokeys' => '|name|version|',
            'version' => '1.0',
        ]];

        $ref  = new \ReflectionClass(Modules::class);
        $prop = $ref->getProperty('injectedModules');
        $prop->setAccessible(true);
        $prop->setValue(null, [1 => [], 0 => []]);
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

    public function testInjectionFailsAndDoesNotRegister(): void
    {
        chdir($this->moduleDir);
        $result = injectmodule('badModule');
        chdir($this->origCwd);

        $this->assertFalse($result);

        $ref  = new \ReflectionClass(Modules::class);
        $prop = $ref->getProperty('injectedModules');
        $prop->setAccessible(true);
        $injected = $prop->getValue();

        $this->assertArrayHasKey('badModule', $injected[0]);
        $this->assertFalse($injected[0]['badModule']);
    }
}
