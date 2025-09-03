<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Hooks {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group hooks
     */
    final class ModuleDisplayWrappersTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!function_exists(__NAMESPACE__ . '\\module_display_events')) {
                $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
                $code = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);\\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }
            eval('namespace Lotgd\\Modules; class HookHandler { public static $calls; public static function displayEvents(string $eventtype, $forcescript = false): void { self::$calls[] = [__FUNCTION__, func_get_args()]; } public static function editorNavs(string $like, string $linkprefix): void { self::$calls[] = [__FUNCTION__, func_get_args()]; } public static function objprefEdit(string $type, string $module, $id): void { self::$calls[] = [__FUNCTION__, func_get_args()]; } }');
            \Lotgd\Modules\HookHandler::$calls = [];
        }

        public function testDisplayWrappers(): void
        {
            module_display_events('foo', true);
            module_editor_navs('bar', 'baz');
            module_objpref_edit('qux', 'quux', 123);

            self::assertSame([
                ['displayEvents', ['foo', true]],
                ['editorNavs', ['bar', 'baz']],
                ['objprefEdit', ['qux', 'quux', 123]],
            ], \Lotgd\Modules\HookHandler::$calls);
        }

        public function testDisplayWrappersDefaultArguments(): void
        {
            module_display_events('foo');
            module_editor_navs('bar', '');

            self::assertSame([
                ['displayEvents', ['foo', false]],
                ['editorNavs', ['bar', '']],
            ], \Lotgd\Modules\HookHandler::$calls);
        }
    }
}
