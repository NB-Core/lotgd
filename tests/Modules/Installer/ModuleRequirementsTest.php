<?php

declare(strict_types=1);

namespace {
    if (! function_exists('modulename_sanitize')) {
        function modulename_sanitize($in)
        {
            return \Lotgd\Sanitize::modulenameSanitize($in);
        }
    }
    if (! function_exists('module_compare_versions')) {
        function module_compare_versions($a, $b): int
        {
            return strcmp($a, $b);
        }
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
    final class ModuleRequirementsTest extends TestCase
    {
        private string $moduleFile;

        protected function setUp(): void
        {
            class_exists(Database::class);
            $this->moduleFile = __DIR__ . '/../../../modules/dep.php';
            file_put_contents($this->moduleFile, "<?php\nfunction dep_getmoduleinfo() { return []; }\n");
        }

        protected function tearDown(): void
        {
            if (file_exists($this->moduleFile)) {
                unlink($this->moduleFile);
            }
            Database::$queryCacheResults = [];
            $prop = new ReflectionProperty(Modules::class, 'injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => [], 0 => []]);
        }

        public function testUninstalledDependencyFails(): void
        {
            $this->assertFalse(Modules::checkRequirements(['dep|1.0']));
        }

        public function testInstalledDependencyVersionMismatchFails(): void
        {
            $filemoddate = date('Y-m-d H:i:s', filemtime($this->moduleFile));
            Database::$queryCacheResults['inject-dep'] = [
                [
                    'active'      => 1,
                    'filemoddate' => $filemoddate,
                    'infokeys'    => '|',
                    'version'     => '1.0',
                ],
            ];

            $this->assertFalse(Modules::checkRequirements(['dep|2.0']));
        }

        public function testForceInjectCallsInject(): void
        {
            $filemoddate = date('Y-m-d H:i:s', filemtime($this->moduleFile));
            Database::$queryCacheResults['inject-dep'] = [
                [
                    'active'      => 1,
                    'filemoddate' => $filemoddate,
                    'infokeys'    => '|',
                    'version'     => '1.0',
                ],
            ];

            $prop = new ReflectionProperty(Modules::class, 'injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => [], 0 => []]);

            $this->assertFalse(Modules::checkRequirements(['dep|1.0'], true));

            $current = $prop->getValue();
            $this->assertArrayHasKey('dep', $current[0]);
            $this->assertFalse($current[0]['dep']);
        }
    }
}
