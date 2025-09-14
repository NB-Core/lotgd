<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Installer {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group installer
     */
    final class ModuleCheckRequirementsWrapperTest extends TestCase
    {
        protected function setUp(): void
        {
            if (! function_exists(__NAMESPACE__ . '\\module_check_requirements')) {
                $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
                $code = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);\\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }

            eval('namespace Lotgd; class Modules { public static $checkRequirementsArgs; public static $checkRequirementsReturn; public static function checkRequirements(array $reqs, bool $forceinject = false): bool { self::$checkRequirementsArgs = [$reqs, $forceinject]; return self::$checkRequirementsReturn; } }');
            \Lotgd\Modules::$checkRequirementsArgs = [];
        }

        public function testModuleCheckRequirementsWrapper(): void
        {
            \Lotgd\Modules::$checkRequirementsReturn = true;

            $result = module_check_requirements(['dep|1.0'], true);

            self::assertTrue($result);
            self::assertSame([
                ['dep|1.0'],
                true,
            ], \Lotgd\Modules::$checkRequirementsArgs);
        }
    }
}
