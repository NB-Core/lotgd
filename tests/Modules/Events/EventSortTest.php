<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Events {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     * @group events
     */
    final class EventSortTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!function_exists(__NAMESPACE__ . '\\event_sort')) {
                $code = file_get_contents(dirname(__DIR__, 3) . '/lib/modules.php');
                $code = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);\\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }

            eval('namespace Lotgd\\Modules; class HookHandler { public static $calls = []; public static $return; public static function eventSort($a, $b): int { self::$calls[] = [$a, $b]; return self::$return; } }');
            \Lotgd\Modules\HookHandler::$calls = [];
        }

        public function testEventSortProxiesToHookHandler(): void
        {
            \Lotgd\Modules\HookHandler::$return = 123;
            $result = event_sort(['a'], ['b']);

            self::assertSame(123, $result);
            self::assertSame([[['a'], ['b']]], \Lotgd\Modules\HookHandler::$calls);
        }
    }
}
