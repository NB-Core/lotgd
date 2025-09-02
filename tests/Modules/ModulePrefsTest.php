<?php

declare(strict_types=1);

namespace {
    if (!function_exists('get_module_pref')) {
        function get_module_pref(string $name, ?string $module = null, ?int $user = null)
        {
            if ($user === false) {
                $user = null;
            }
            return \Lotgd\Modules\HookHandler::getModulePref($name, $module, $user);
        }
    }
    if (!function_exists('set_module_pref')) {
        function set_module_pref(string $name, mixed $value, ?string $module = null, ?int $user = null): void
        {
            if ($user === false) {
                $user = null;
            }
            \Lotgd\Modules\HookHandler::setModulePref($name, $value, $module, $user);
        }
    }
    if (!function_exists('increment_module_pref')) {
        function increment_module_pref(string $name, int|float $value = 1, ?string $module = null, ?int $user = null): void
        {
            if ($user === false) {
                $user = null;
            }
            \Lotgd\Modules\HookHandler::incrementModulePref($name, $value, $module, $user);
        }
    }
    if (!function_exists('clear_module_pref')) {
        function clear_module_pref(string $name, ?string $module = null, ?int $user = null): void
        {
            \Lotgd\Modules\HookHandler::clearModulePref($name, $module, $user);
        }
    }
    if (!function_exists('modulename_sanitize')) {
        function modulename_sanitize($in)
        {
            return \Lotgd\Sanitize::modulenameSanitize($in);
        }
    }
}

namespace Lotgd\Tests\Modules {

use PHPUnit\Framework\TestCase;

final class ModulePrefsTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $module_prefs, $mostrecentmodule, $mysqli;
        $session = ['user' => ['acctid' => 1, 'loggedin' => true]];
        $module_prefs = [1 => ['modA' => [], '' => []]];
        $mostrecentmodule = '';
        $mysqli = null;
    }

    public function testExplicitModuleAndUser(): void
    {
        set_module_pref('flag', 'on', 'modA', 1);
        $this->assertSame('on', get_module_pref('flag', 'modA', 1));

        set_module_pref('count', 0, 'modA', 1);
        increment_module_pref('count', 1, 'modA', 1);
        increment_module_pref('count', 1, 'modA', 1);
        increment_module_pref('count', 1, 'modA', 1);
        $this->assertSame(3.0, get_module_pref('count', 'modA', 1));

        clear_module_pref('flag', 'modA', 1);
        $this->assertNull(get_module_pref('flag', 'modA', 1));
    }

    public function testFallbackUserAndModule(): void
    {
        global $mostrecentmodule;
        $mostrecentmodule = 'modA';

        set_module_pref('flag', 'on', '', null);
        $this->assertSame('on', get_module_pref('flag', '', null));

        set_module_pref('count', 0, '', null);
        increment_module_pref('count', 1, '', null);
        increment_module_pref('count', 1, '', null);
        increment_module_pref('count', 1, '', null);
        $this->assertSame(3.0, get_module_pref('count', '', null));

        clear_module_pref('flag', '', null);
        $this->assertNull(get_module_pref('flag', '', null));
    }
}

}
