<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Events {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group events
     */
    final class ModuleEventWrappersTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!function_exists(__NAMESPACE__ . '\\module_sem_acquire')) {
                $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
                $code = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);\\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }
            eval('namespace Lotgd\\Modules; class HookHandler { public static $calls; public static $collectEventsReturn; public static $moduleEventsReturn; public static function semAcquire(): void { self::$calls[] = [__FUNCTION__, func_get_args()]; } public static function semRelease(): void { self::$calls[] = [__FUNCTION__, func_get_args()]; } public static function collectEvents(string $type, bool $allowinactive = false): array { self::$calls[] = [__FUNCTION__, func_get_args()]; return self::$collectEventsReturn; } public static function moduleEvents(string $eventtype, int $basechance, ?string $baseLink = null): int { self::$calls[] = [__FUNCTION__, func_get_args()]; return self::$moduleEventsReturn; } public static function doEvent(string $type, string $module, bool $allowinactive = false, ?string $baseLink = null): void { self::$calls[] = [__FUNCTION__, func_get_args()]; } }');
            \Lotgd\Modules\HookHandler::$calls = [];
        }

        public function testEventWrappers(): void
        {
            \Lotgd\Modules\HookHandler::$collectEventsReturn = ['alpha'];
            \Lotgd\Modules\HookHandler::$moduleEventsReturn = 7;

            module_sem_acquire();
            module_sem_release();
            $collect = module_collect_events('foo', true);
            $events = module_events('bar', 3, 'baz');
            module_do_event('qux', 'corge', false, 'grault');

            $collectDefault = module_collect_events('type');
            $eventsDefaultLink = module_events('type', 0);
            $eventsNullLink = module_events('type', 1, null);
            module_do_event('type', '', true);

            self::assertSame(['alpha'], $collect);
            self::assertSame(7, $events);
            self::assertSame(['alpha'], $collectDefault);
            self::assertSame(7, $eventsDefaultLink);
            self::assertSame(7, $eventsNullLink);
            self::assertSame([
                ['semAcquire', []],
                ['semRelease', []],
                ['collectEvents', ['foo', true]],
                ['moduleEvents', ['bar', 3, 'baz']],
                ['doEvent', ['qux', 'corge', false, 'grault']],
                ['collectEvents', ['type', false]],
                ['moduleEvents', ['type', 0, null]],
                ['moduleEvents', ['type', 1, null]],
                ['doEvent', ['type', '', true, null]],
            ], \Lotgd\Modules\HookHandler::$calls);
        }
    }
}
