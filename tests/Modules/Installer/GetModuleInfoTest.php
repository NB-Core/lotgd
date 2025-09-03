<?php

declare(strict_types=1);

namespace {
    if (!function_exists('modulename_sanitize')) {
        function modulename_sanitize($in)
        {
            return \Lotgd\Sanitize::modulenameSanitize($in);
        }
    }
}

namespace Lotgd\Tests\Modules\Installer {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group installer
     */
    final class GetModuleInfoTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!function_exists(__NAMESPACE__ . '\\get_module_info')) {
                $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
                $code = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);\\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }

            eval('namespace Lotgd; class Modules { public static $getModuleInfoArgs; public static $getModuleInfoReturn; public static function getModuleInfo(string $shortname, bool $with_db = true): array { self::$getModuleInfoArgs = [$shortname, $with_db]; return self::$getModuleInfoReturn; } }');
            \Lotgd\Modules::$getModuleInfoArgs = [];
        }

        public function testGetModuleInfoWrapperSanitizesName(): void
        {
            \Lotgd\Modules::$getModuleInfoReturn = ['stub' => 'data'];

            $result = get_module_info('../foo');

            self::assertSame(['stub' => 'data'], $result);
            self::assertSame(['foo', true], \Lotgd\Modules::$getModuleInfoArgs);
        }
    }
}
