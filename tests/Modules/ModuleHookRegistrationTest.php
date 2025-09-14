<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Stubs {
    class HookHandler
    {
        public static array $calls = [];

        public static function reset(): void
        {
            self::$calls = [];
        }
        public static function addHook(string $hookname, $functioncall = false, $whenactive = false): void
        {
            self::$calls[] = ['method' => 'addHook', 'args' => [$hookname, $functioncall, $whenactive]];
        }

        public static function addHookPriority(string $hookname, int $priority = 50, $functioncall = false, $whenactive = false): void
        {
            self::$calls[] = ['method' => 'addHookPriority', 'args' => [$hookname, $priority, $functioncall, $whenactive]];
        }

        public static function dropHook(string $hookname, $functioncall = false): void
        {
            self::$calls[] = ['method' => 'dropHook', 'args' => [$hookname, $functioncall]];
        }
    }
}

namespace Lotgd\Tests\Modules {
    function module_drophook(string $hookname, $functioncall = false): void
    {
        \Lotgd\Modules\HookHandler::dropHook($hookname, $functioncall);
    }

    function module_addhook(string $hookname, $functioncall = false, $whenactive = false): void
    {
        \Lotgd\Modules\HookHandler::addHook($hookname, $functioncall, $whenactive);
    }

    function module_addhook_priority(string $hookname, int $priority = 50, $functioncall = false, $whenactive = false): void
    {
        \Lotgd\Modules\HookHandler::addHookPriority($hookname, $priority, $functioncall, $whenactive);
    }
}

namespace Lotgd\Tests\Modules {

    use Lotgd\Tests\Modules\Stubs\HookHandler;
    use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @group hooks
 */
    final class ModuleHookRegistrationTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!class_exists('Lotgd\\Modules\\HookHandler', false)) {
                class_alias(HookHandler::class, 'Lotgd\\Modules\\HookHandler');
            }
            HookHandler::reset();
        }

        public function testModuleAddHookForwardsEmptyWhenactive(): void
        {
            module_addhook('hook', 'callback', '');

            $this->assertSame(
                [['method' => 'addHook', 'args' => ['hook', 'callback', '']]],
                HookHandler::$calls
            );
        }

        public function testModuleAddHookPriorityForwardsCustomPriority(): void
        {
            module_addhook_priority('hook', 75, 'callback', 'active');

            $this->assertSame(
                [['method' => 'addHookPriority', 'args' => ['hook', 75, 'callback', 'active']]],
                HookHandler::$calls
            );
        }

        public function testModuleDropHookNonExistentIsGraceful(): void
        {
            module_drophook('missing');

            $this->assertSame(
                [['method' => 'dropHook', 'args' => ['missing', false]]],
                HookHandler::$calls
            );
        }
    }
}
