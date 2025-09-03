<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Installer {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group installer
     */
    final class ModuleActiveInstalledTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!function_exists(__NAMESPACE__ . '\\is_module_active')) {
                $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
                $code = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);\\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }
            eval('namespace Lotgd; class Modules { public static $isActiveArgs; public static $isActiveReturn; public static function isActive(string $modulename): bool { self::$isActiveArgs[] = $modulename; return self::$isActiveReturn; } public static $isInstalledArgs; public static $isInstalledReturn; public static function isInstalled(string $modulename, string|false $version = false): bool { self::$isInstalledArgs[] = $modulename; return self::$isInstalledReturn; } }');
            \Lotgd\Modules::$isActiveArgs = [];
            \Lotgd\Modules::$isInstalledArgs = [];
        }

        public function testDelegatesWithModuleFoo(): void
        {
            \Lotgd\Modules::$isActiveReturn = true;
            \Lotgd\Modules::$isInstalledReturn = true;

            $active = is_module_active('foo');
            $installed = is_module_installed('foo');

            self::assertTrue($active);
            self::assertTrue($installed);
            self::assertSame(['foo'], \Lotgd\Modules::$isActiveArgs);
            self::assertSame(['foo'], \Lotgd\Modules::$isInstalledArgs);
        }

        public function testNonexistentModuleReturnsFalse(): void
        {
            \Lotgd\Modules::$isActiveReturn = false;
            \Lotgd\Modules::$isInstalledReturn = false;
            \Lotgd\Modules::$isActiveArgs = [];
            \Lotgd\Modules::$isInstalledArgs = [];

            $active = is_module_active('bar');
            $installed = is_module_installed('bar');

            self::assertFalse($active);
            self::assertFalse($installed);
            self::assertSame(['bar'], \Lotgd\Modules::$isActiveArgs);
            self::assertSame(['bar'], \Lotgd\Modules::$isInstalledArgs);
        }
    }
}
