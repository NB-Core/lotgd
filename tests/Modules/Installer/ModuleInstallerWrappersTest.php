<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Installer {
    use PHPUnit\Framework\TestCase;

    $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
    $code = preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/', '', $code);
    eval('namespace ' . __NAMESPACE__ . '; ' . $code);

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group installer
     */
    final class ModuleInstallerWrappersTest extends TestCase
    {
        protected function setUp(): void
        {
            eval('namespace Lotgd\\Modules; class Installer { public static $activateArgs; public static $activateReturn; public static $deactivateArgs; public static $deactivateReturn; public static $installArgs; public static $installReturn; public static $uninstallArgs; public static $uninstallReturn; public static $conditionArgs; public static $conditionReturn; public static $statusArgs; public static $statusReturn; public static function activate(string $module): bool { self::$activateArgs = [$module]; return self::$activateReturn; } public static function deactivate(string $module): bool { self::$deactivateArgs = [$module]; return self::$deactivateReturn; } public static function install(string $module, bool $force = true): bool { self::$installArgs = [$module, $force]; return self::$installReturn; } public static function uninstall(string $module): bool { self::$uninstallArgs = [$module]; return self::$uninstallReturn; } public static function condition(string $condition): bool { self::$conditionArgs = [$condition]; return self::$conditionReturn; } public static function getInstallStatus(bool $with_db = true): array { self::$statusArgs = [$with_db]; return self::$statusReturn; } }');
            \Lotgd\Modules\Installer::$activateArgs = [];
            \Lotgd\Modules\Installer::$deactivateArgs = [];
            \Lotgd\Modules\Installer::$installArgs = [];
            \Lotgd\Modules\Installer::$uninstallArgs = [];
            \Lotgd\Modules\Installer::$conditionArgs = [];
            \Lotgd\Modules\Installer::$statusArgs = [];
        }

        public function testActivateModule(): void
        {
            \Lotgd\Modules\Installer::$activateReturn = true;
            $result = activate_module('foo');
            self::assertTrue($result);
            self::assertSame(['foo'], \Lotgd\Modules\Installer::$activateArgs);
        }

        public function testDeactivateModule(): void
        {
            \Lotgd\Modules\Installer::$deactivateReturn = false;
            $result = deactivate_module('bar');
            self::assertFalse($result);
            self::assertSame(['bar'], \Lotgd\Modules\Installer::$deactivateArgs);
        }

        public function testInstallModule(): void
        {
            \Lotgd\Modules\Installer::$installReturn = true;
            $result = install_module('baz');
            self::assertTrue($result);
            self::assertSame(['baz', true], \Lotgd\Modules\Installer::$installArgs);
        }

        public function testUninstallModule(): void
        {
            \Lotgd\Modules\Installer::$uninstallReturn = true;
            $result = uninstall_module('qux');
            self::assertTrue($result);
            self::assertSame(['qux'], \Lotgd\Modules\Installer::$uninstallArgs);
        }

        public function testModuleCondition(): void
        {
            \Lotgd\Modules\Installer::$conditionReturn = true;
            $result = module_condition('2 > 1');
            self::assertTrue($result);
            self::assertSame(['2 > 1'], \Lotgd\Modules\Installer::$conditionArgs);
        }

        public function testGetInstallStatus(): void
        {
            \Lotgd\Modules\Installer::$statusReturn = ['ok'];
            $result = get_module_install_status(false);
            self::assertSame(['ok'], $result);
            self::assertSame([false], \Lotgd\Modules\Installer::$statusArgs);
        }
    }
}
