<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Hooks {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group hooks
     */
    final class ModuleUtilityWrappersTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!function_exists(__NAMESPACE__ . '\\get_module_install_status')) {
                $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
                $code = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);\\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }

            eval('namespace Lotgd\\Modules; class Installer { public static $getInstallStatusArgs; public static $getInstallStatusReturn; public static function getInstallStatus(bool $with_db = true): array { self::$getInstallStatusArgs = [$with_db]; return self::$getInstallStatusReturn; } }');
            \Lotgd\Modules\Installer::$getInstallStatusArgs = [];

            eval('namespace Lotgd; class Modules { public static $getRaceNameArgs; public static $getRaceNameReturn; public static function getRaceName($thisuser = true): string { self::$getRaceNameArgs = [$thisuser]; return self::$getRaceNameReturn; } }');
            \Lotgd\Modules::$getRaceNameArgs = [];
        }

        public function testUtilityWrappers(): void
        {
            \Lotgd\Modules\Installer::$getInstallStatusReturn = ['ok'];
            \Lotgd\Modules::$getRaceNameReturn = 'orc';

            $status = get_module_install_status(false);
            self::assertSame(['ok'], $status);
            self::assertSame([false], \Lotgd\Modules\Installer::$getInstallStatusArgs);

            $status = get_module_install_status();
            self::assertSame(['ok'], $status);
            self::assertSame([true], \Lotgd\Modules\Installer::$getInstallStatusArgs);

            $race = get_racename();
            self::assertSame('orc', $race);
            self::assertSame([true], \Lotgd\Modules::$getRaceNameArgs);

            $race = get_racename('');
            self::assertSame('orc', $race);
            self::assertSame([''], \Lotgd\Modules::$getRaceNameArgs);
        }
    }
}
