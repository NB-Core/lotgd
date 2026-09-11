<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * lib/modules.php exposes the legacy procedural module API, and 45 of its
     * functions are one-line forwards to Lotgd\Modules, Lotgd\Modules\HookHandler
     * or Lotgd\Modules\Installer. Modules that ship with the game call the
     * procedural names, so a forward that loses an argument, reorders two, or
     * points at the wrong method breaks those modules silently.
     *
     * The table below is written out by hand on purpose: deriving it from
     * lib/modules.php would only assert that the file agrees with itself.
     *
     * This replaces eight near-identical classes that covered 15 of the 45
     * forwards between them, each in its own process with its own hand-written
     * recording double. The three targets are stubbed here with __callStatic,
     * so one recorder covers every method without naming any of them.
     *
     * Functions with real logic of their own -- mass_module_prepare's empty-array
     * shortcut, get_module_info's name sanitising, the module_pref trio's user
     * defaulting -- are deliberately absent; they have their own tests.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class LegacyApiDelegationTest extends TestCase
    {
        /** @var array<int, array{class: string, method: string, args: array<int, mixed>}> */
        public static array $calls = [];

        public static mixed $nextReturn = null;

        protected function setUp(): void
        {
            self::$calls = [];
            self::$nextReturn = null;

            if (!function_exists(__NAMESPACE__ . '\\injectmodule')) {
                $recorder = <<<'RECORDER'
                namespace Lotgd\Modules;

                class HookHandler
                {
                    public static function __callStatic(string $name, array $args): mixed
                    {
                        return \Lotgd\Tests\Modules\LegacyApiDelegationTest::record('HookHandler', $name, $args);
                    }
                }

                class Installer
                {
                    public static function __callStatic(string $name, array $args): mixed
                    {
                        return \Lotgd\Tests\Modules\LegacyApiDelegationTest::record('Installer', $name, $args);
                    }
                }

                namespace Lotgd;

                class Modules
                {
                    public static function __callStatic(string $name, array $args): mixed
                    {
                        return \Lotgd\Tests\Modules\LegacyApiDelegationTest::record('Modules', $name, $args);
                    }
                }
                RECORDER;
                eval($recorder);

                $code = file_get_contents(dirname(__DIR__, 2) . '/lib/modules.php');
                $code = preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/', '', $code);
                eval('namespace ' . __NAMESPACE__ . '; ' . $code);
            }
        }

        public static function record(string $class, string $method, array $args): mixed
        {
            self::$calls[] = ['class' => $class, 'method' => $method, 'args' => $args];

            return self::$nextReturn;
        }

        /**
         * @param array<int, mixed> $args
         */
        #[DataProvider('delegationProvider')]
        public function testLegacyFunctionForwardsToItsClass(
            string $function,
            string $expectedClass,
            string $expectedMethod,
            array $args,
            mixed $return
        ): void {
            self::$nextReturn = $return;

            $result = (__NAMESPACE__ . '\\' . $function)(...$args);

            self::assertCount(1, self::$calls, "{$function}() should make exactly one call");
            self::assertSame($expectedClass, self::$calls[0]['class']);
            self::assertSame($expectedMethod, self::$calls[0]['method']);
            self::assertSame($args, self::$calls[0]['args'], "{$function}() must forward its arguments unchanged");

            if ($return !== null) {
                self::assertSame($return, $result, "{$function}() must return what its class returned");
            }
        }

        /**
         * @return array<string, array{0: string, 1: string, 2: string, 3: array<int, mixed>, 4: mixed}>
         */
        public static function delegationProvider(): array
        {
            return [
            'injectmodule' => ['injectmodule', 'Modules', 'inject', ['s0', false, true], true],
            'module_status' => ['module_status', 'Modules', 'getStatus', ['s0', 's1'], 7],
            'is_module_active' => ['is_module_active', 'Modules', 'isActive', ['s0'], true],
            'is_module_installed' => ['is_module_installed', 'Modules', 'isInstalled', ['s0', 's1'], true],
            'module_check_requirements' => ['module_check_requirements', 'Modules', 'checkRequirements', [['a0'], false], true],
            'blockmodule' => ['blockmodule', 'HookHandler', 'block', ['s0'], null],
            'unblockmodule' => ['unblockmodule', 'HookHandler', 'unblock', ['s0'], null],
            'modulehook' => ['modulehook', 'HookHandler', 'hook', ['s0', ['a1'], true, 'x3'], ['ok']],
            'get_all_module_settings' => ['get_all_module_settings', 'HookHandler', 'getAllModuleSettings', ['s0'], ['ok']],
            'get_module_setting' => ['get_module_setting', 'HookHandler', 'getModuleSetting', ['s0', 's1'], 'value'],
            'set_module_setting' => ['set_module_setting', 'HookHandler', 'setModuleSetting', ['s0', 'm1', 's2'], null],
            'increment_module_setting' => ['increment_module_setting', 'HookHandler', 'incrementModuleSetting', ['s0', 11, 's2'], null],
            'clear_module_settings' => ['clear_module_settings', 'HookHandler', 'clearModuleSettings', ['s0'], null],
            'load_module_settings' => ['load_module_settings', 'HookHandler', 'loadModuleSettings', ['s0'], null],
            'module_delete_objprefs' => ['module_delete_objprefs', 'HookHandler', 'deleteObjPrefs', ['s0', 'x1'], null],
            'get_module_objpref' => ['get_module_objpref', 'HookHandler', 'getObjPref', ['s0', 'x1', 's2', 's3'], 'value'],
            'set_module_objpref' => ['set_module_objpref', 'HookHandler', 'setObjPref', ['s0', 'x1', 's2', 'm3', 's4'], null],
            'increment_module_objpref' => ['increment_module_objpref', 'HookHandler', 'incrementObjPref', ['s0', 'x1', 's2', 13, 's4'], null],
            'module_delete_userprefs' => ['module_delete_userprefs', 'HookHandler', 'deleteUserPrefs', [10], null],
            'get_all_module_prefs' => ['get_all_module_prefs', 'HookHandler', 'getAllModulePrefs', ['s0', 11], ['ok']],
            'clear_module_pref' => ['clear_module_pref', 'HookHandler', 'clearModulePref', ['s0', 's1', 12], null],
            'load_module_prefs' => ['load_module_prefs', 'HookHandler', 'loadModulePrefs', ['s0', 11], null],
            'module_wipehooks' => ['module_wipehooks', 'HookHandler', 'wipeHooks', [], null],
            'module_addeventhook' => ['module_addeventhook', 'HookHandler', 'addEventHook', ['s0', 's1'], null],
            'module_dropeventhook' => ['module_dropeventhook', 'HookHandler', 'dropEventHook', ['s0'], null],
            'module_drophook' => ['module_drophook', 'HookHandler', 'dropHook', ['s0', 'x1'], null],
            'module_addhook' => ['module_addhook', 'HookHandler', 'addHook', ['s0', 'x1', 'x2'], null],
            'module_addhook_priority' => ['module_addhook_priority', 'HookHandler', 'addHookPriority', ['s0', 11, 'x2', 'x3'], null],
            'module_sem_acquire' => ['module_sem_acquire', 'HookHandler', 'semAcquire', [], null],
            'module_sem_release' => ['module_sem_release', 'HookHandler', 'semRelease', [], null],
            'module_collect_events' => ['module_collect_events', 'HookHandler', 'collectEvents', ['s0', false], ['ok']],
            'module_events' => ['module_events', 'HookHandler', 'moduleEvents', ['s0', 11, 's2'], 7],
            'module_do_event' => ['module_do_event', 'HookHandler', 'doEvent', ['s0', 's1', true, 's3'], null],
            'event_sort' => ['event_sort', 'HookHandler', 'eventSort', ['x0', 'x1'], 7],
            'module_display_events' => ['module_display_events', 'HookHandler', 'displayEvents', ['s0', 'x1'], null],
            'module_editor_navs' => ['module_editor_navs', 'HookHandler', 'editorNavs', ['s0', 's1'], null],
            'module_objpref_edit' => ['module_objpref_edit', 'HookHandler', 'objprefEdit', ['s0', 's1', 'x2'], null],
            'module_compare_versions' => ['module_compare_versions', 'HookHandler', 'compareVersions', ['x0', 'x1'], 7],
            'activate_module' => ['activate_module', 'Installer', 'activate', ['s0'], true],
            'deactivate_module' => ['deactivate_module', 'Installer', 'deactivate', ['s0'], true],
            'uninstall_module' => ['uninstall_module', 'Installer', 'uninstall', ['s0'], true],
            'install_module' => ['install_module', 'Installer', 'install', ['s0', false], true],
            'module_condition' => ['module_condition', 'Installer', 'condition', ['s0'], true],
            'get_module_install_status' => ['get_module_install_status', 'Installer', 'getInstallStatus', [true], ['ok']],
            'get_racename' => ['get_racename', 'Modules', 'getRaceName', ['x0'], 'name'],
            ];
        }
    }
}
