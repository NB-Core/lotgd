<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Injection;

use Lotgd\Modules;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

function injectmodule(string $moduleName): bool
{
    return Modules::inject($moduleName);
}

/**
 * @group injection
 */
final class InjectModuleFailureTest extends TestCase
{
    private string $moduleFile;

    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$queryCacheResults = [];
        $this->moduleFile = __DIR__ . '/../../../modules/inactive.php';
        file_put_contents($this->moduleFile, "<?php\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->moduleFile)) {
            unlink($this->moduleFile);
        }
        \Lotgd\MySQL\Database::$queryCacheResults = [];
    }

    public function testNonexistentModuleReturnsFalse(): void
    {
        $this->assertFalse(injectmodule('nonexistent'));
    }

    public function testInactiveModuleReturnsFalse(): void
    {
        \Lotgd\MySQL\Database::$queryCacheResults['inject-inactive'] = [['active' => 0]];
        $this->assertFalse(injectmodule('inactive'));
    }

    public function testSanitizedPathStillFails(): void
    {
        \Lotgd\MySQL\Database::$queryCacheResults['inject-inactive'] = [['active' => 0]];
        $this->assertFalse(injectmodule('../inactive'));
    }
}
