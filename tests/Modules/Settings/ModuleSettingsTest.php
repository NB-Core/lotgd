<?php

declare(strict_types=1);

namespace {
    if (!function_exists('set_module_setting')) {
        function set_module_setting(string $name, mixed $value, ?string $module = null): void
        {
            \Lotgd\Modules\HookHandler::setModuleSetting($name, $value, $module);
        }
    }
    if (!function_exists('get_module_setting')) {
        function get_module_setting(string $name, ?string $module = null): mixed
        {
            return \Lotgd\Modules\HookHandler::getModuleSetting($name, $module);
        }
    }
    if (!function_exists('increment_module_setting')) {
        function increment_module_setting(string $name, int|float $value = 1, ?string $module = null): void
        {
            \Lotgd\Modules\HookHandler::incrementModuleSetting($name, $value, $module);
        }
    }
    if (!function_exists('clear_module_settings')) {
        function clear_module_settings(?string $module = null): void
        {
            \Lotgd\Modules\HookHandler::clearModuleSettings($module);
        }
    }
    if (!function_exists('get_all_module_settings')) {
        function get_all_module_settings(?string $module = null): array
        {
            return \Lotgd\Modules\HookHandler::getAllModuleSettings($module);
        }
    }
}

namespace Lotgd\Tests\Modules\Settings {

    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;
    use Lotgd\Modules\ModuleManager;

/**
 * @group settings
 */
    final class ModuleSettingsTest extends TestCase
    {
        protected function setUp(): void
        {
            class_exists(Database::class);
            \Lotgd\MySQL\Database::$queryCacheResults = [];
            \Lotgd\MySQL\Database::$lastSql = '';
            ModuleManager::setSettings([]);
            ModuleManager::setMostRecentModule('');
        }

        public function testModuleSettingLifecycle(): void
        {
            ModuleManager::setMostRecentModule('mymodule');

            set_module_setting('key', 'value');
            $this->assertSame('value', get_module_setting('key'));

            set_module_setting('counter', '0');
            increment_module_setting('counter');
            increment_module_setting('counter');
            $this->assertSame(2.0, get_module_setting('counter'));

            clear_module_settings();
            $this->assertNull(get_module_setting('key'));
            $this->assertNull(get_module_setting('counter'));
        }

        public function testModuleSettingLifecycleWithEmptyModule(): void
        {
            set_module_setting('key', 'value', '');
            $this->assertSame('value', get_module_setting('key', ''));

            set_module_setting('counter', '0', '');
            increment_module_setting('counter', 1, '');
            increment_module_setting('counter', 1, '');
            $this->assertSame(2.0, get_module_setting('counter', ''));

            clear_module_settings('');
            $this->assertNull(get_module_setting('key', ''));
            $this->assertNull(get_module_setting('counter', ''));
        }

        public function testGetAllModuleSettings(): void
        {
            ModuleManager::setMostRecentModule('mymodule');

            set_module_setting('key', 'value');
            set_module_setting('counter', '0');
            increment_module_setting('counter');
            increment_module_setting('counter');

            $settings = get_all_module_settings('mymodule');

            $this->assertSame([
            'key' => 'value',
            'counter' => 2.0,
            ], $settings);
        }

        public function testIncrementModuleSettingWithNegativeAndFractionalValues(): void
        {
            ModuleManager::setMostRecentModule('mymodule');

            foreach ([-1.0, 1.5] as $increment) {
                set_module_setting('counter', '0');
                increment_module_setting('counter', $increment);

                $this->assertSame($increment, get_module_setting('counter'), "increment {$increment}");
            }
        }

        public function testSetModuleSettingAcceptsInteger(): void
        {
            ModuleManager::setMostRecentModule('mymodule');

            set_module_setting('number', 42);

            $this->assertSame(42, get_module_setting('number'));
        }
    }

}
