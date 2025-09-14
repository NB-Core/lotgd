<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\ModuleWipeHooks\Stubs {
    class HookHandler
    {
        public static bool $wiped = false;

        public static function reset(): void
        {
            self::$wiped = false;
        }

        public static function wipeHooks(): void
        {
            self::$wiped = true;
        }
    }
}

namespace Lotgd\Tests\Modules\Hooks {
    function module_wipehooks(): void
    {
        \Lotgd\Modules\HookHandler::wipeHooks();
    }
}

namespace Lotgd\Tests\Modules\Hooks {

    use Lotgd\Tests\Modules\ModuleWipeHooks\Stubs\HookHandler;
    use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @group hooks
 */
    final class ModuleWipeHooksTest extends TestCase
    {
        protected function setUp(): void
        {
            if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
                class_alias(HookHandler::class, 'Lotgd\\Modules\\HookHandler');
            }

            HookHandler::reset();
        }

        public function testModuleWipeHooksCallsHookHandler(): void
        {
            \Lotgd\Tests\Modules\Hooks\module_wipehooks();

            $this->assertTrue(HookHandler::$wiped);
        }
    }

}
