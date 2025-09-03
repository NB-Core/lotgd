<?php

declare(strict_types=1);

namespace {
    if (! function_exists('modulename_sanitize')) {
        function modulename_sanitize($in)
        {
            return \Lotgd\Sanitize::modulenameSanitize($in);
        }
    }

    if (! defined('MODULE_OUT_OF_DATE')) {
        define('MODULE_OUT_OF_DATE', MODULE_VERSION_TOO_LOW);
    }
}

namespace Lotgd\Tests\Modules\Installer {
    use Lotgd\Modules;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    /**
     * @group installer
     */
    final class ModuleStatusTest extends TestCase
    {
        protected function setUp(): void
        {
            class_exists(Database::class);
        }

        protected function tearDown(): void
        {
            Database::$queryCacheResults = [];
            $prop = new ReflectionProperty(Modules::class, 'injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => [], 0 => []]);
        }

        public function testMissingFileReturnsFileNotPresent(): void
        {
            $status = Modules::getStatus('missingmodule');
            $this->assertSame(MODULE_FILE_NOT_PRESENT, $status);
        }

        public function testInstalledInactiveReturnsInstalledOnly(): void
        {
            $name = 'inactivemodule';
            $file = __DIR__ . "/../../../modules/{$name}.php";
            file_put_contents($file, "<?php\n");
            Database::$queryCacheResults["inject-$name"] = [
                ['active' => 0, 'filemoddate' => '', 'infokeys' => '|', 'version' => '1.0'],
            ];
            $status = Modules::getStatus($name);
            $mask   = MODULE_INSTALLED | MODULE_ACTIVE | MODULE_INJECTED;
            $this->assertSame(MODULE_INSTALLED, $status & $mask);
            unlink($file);
        }

        public function testActiveAndInjectedReturnsInstalledActiveInjected(): void
        {
            $name = 'activemodule';
            $file = __DIR__ . "/../../../modules/{$name}.php";
            file_put_contents($file, "<?php\n");
            Database::$queryCacheResults["inject-$name"] = [
                ['active' => 1, 'filemoddate' => '', 'infokeys' => '|', 'version' => '1.0'],
            ];
            $prop    = new ReflectionProperty(Modules::class, 'injectedModules');
            $prop->setAccessible(true);
            $current = $prop->getValue();
            $current[0][$name] = true;
            $prop->setValue(null, $current);
            $status = Modules::getStatus($name);
            $mask   = MODULE_INSTALLED | MODULE_ACTIVE | MODULE_INJECTED;
            $this->assertSame(MODULE_INSTALLED | MODULE_ACTIVE | MODULE_INJECTED, $status & $mask);
            unlink($file);
        }

        public function testVersionTooLowReturnsOutOfDate(): void
        {
            $name = 'outofdatemodule';
            $file = __DIR__ . "/../../../modules/{$name}.php";
            file_put_contents($file, "<?php\n");
            Database::$queryCacheResults["inject-$name"] = [
                ['active' => 1, 'filemoddate' => '', 'infokeys' => '|', 'version' => '1.0'],
            ];
            $status = Modules::getStatus($name, '2.0');
            $this->assertSame(MODULE_OUT_OF_DATE, $status & MODULE_OUT_OF_DATE);
            unlink($file);
        }
    }
}
