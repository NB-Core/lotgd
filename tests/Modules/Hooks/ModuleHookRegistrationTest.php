<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs {
    class HookHandler
    {
        public static array $calls = [];

        public static function reset(): void
        {
            self::$calls = [];
        }

        public static function addEventHook(string $type, string $chance): void
        {
            self::$calls[] = ['method' => 'addEventHook', 'args' => [$type, $chance]];
        }

        public static function dropEventHook(string $type): void
        {
            self::$calls[] = ['method' => 'dropEventHook', 'args' => [$type]];
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

namespace Lotgd\Tests\Modules\Hooks {
    function module_addeventhook(string $type, string $chance): void
    {
        \Lotgd\Modules\HookHandler::addEventHook($type, $chance);
    }

    function module_dropeventhook(string $type): void
    {
        \Lotgd\Modules\HookHandler::dropEventHook($type);
    }

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

namespace Lotgd\Tests\Modules\Hooks {

    use Lotgd\Tests\Stubs\HookHandler;
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

        public function testModuleAddEventHookForwardsParameters(): void
        {
            \Lotgd\Tests\Modules\Hooks\module_addeventhook('event', '50');

            $this->assertSame(
                [['method' => 'addEventHook', 'args' => ['event', '50']]],
                HookHandler::$calls
            );
        }

        public function testModuleDropEventHookForwardsParameters(): void
        {
            \Lotgd\Tests\Modules\Hooks\module_dropeventhook('event');

            $this->assertSame(
                [['method' => 'dropEventHook', 'args' => ['event']]],
                HookHandler::$calls
            );
        }

        public function testModuleAddHookForwardsEmptyWhenactive(): void
        {
            \Lotgd\Tests\Modules\Hooks\module_addhook('hook', 'callback', '');

            $this->assertSame(
                [['method' => 'addHook', 'args' => ['hook', 'callback', '']]],
                HookHandler::$calls
            );
        }

        public function testModuleAddHookPriorityForwardsCustomPriority(): void
        {
            \Lotgd\Tests\Modules\Hooks\module_addhook_priority('hook', 75, 'callback', 'active');

            $this->assertSame(
                [['method' => 'addHookPriority', 'args' => ['hook', 75, 'callback', 'active']]],
                HookHandler::$calls
            );
        }

        public function testModuleDropHookNonExistentIsGraceful(): void
        {
            \Lotgd\Tests\Modules\Hooks\module_drophook('missing');

            $this->assertSame(
                [['method' => 'dropHook', 'args' => ['missing', false]]],
                HookHandler::$calls
            );
        }
    }
}
