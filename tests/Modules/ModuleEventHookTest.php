<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Fixtures {
    /**
     * Simple mock used to capture calls to the HookHandler facade.
     */
    if (!class_exists(HookHandlerMock::class, false)) {
        class HookHandlerMock
        {
            public static array $added = [];
            public static array $dropped = [];

            public static function addEventHook(string $type, string $chance): void
            {
                self::$added[] = ['type' => $type, 'chance' => $chance];
            }

            public static function dropEventHook(string $type): void
            {
                self::$dropped[] = ['type' => $type];
            }
        }
    }
}

namespace Lotgd\Tests\Modules {

    use Lotgd\Tests\Modules\Fixtures\HookHandlerMock;
    use PHPUnit\Framework\TestCase;

    function module_addeventhook(string $type, string $chance): void
    {
        HookHandlerMock::addEventHook($type, $chance);
    }

    function module_dropeventhook(string $type): void
    {
        HookHandlerMock::dropEventHook($type);
    }

/**
 * @group modules
 */
    final class ModuleEventHookTest extends TestCase
    {
        protected function setUp(): void
        {
            HookHandlerMock::$added = [];
            HookHandlerMock::$dropped = [];
        }

        public function testAddEventHookPassesTypeAndChance(): void
        {
            module_addeventhook('forest', '25%');
            self::assertSame([
            ['type' => 'forest', 'chance' => '25%'],
            ], HookHandlerMock::$added);
        }

        public function testDropEventHookPassesType(): void
        {
            module_dropeventhook('forest');
            self::assertSame([
            ['type' => 'forest'],
            ], HookHandlerMock::$dropped);
        }

        public function testEmptyEventType(): void
        {
            module_addeventhook('', '10%');
            module_dropeventhook('');

            self::assertSame([
            ['type' => '', 'chance' => '10%'],
            ], HookHandlerMock::$added);
            self::assertSame([
            ['type' => ''],
            ], HookHandlerMock::$dropped);
        }
    }
}
