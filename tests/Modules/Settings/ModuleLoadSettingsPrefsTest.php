<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Settings\Stubs {
    class HookHandler
    {
        public static ?int $lastUser = null;

        public static function loadModuleSettings(string $module): void
        {
            \Lotgd\Modules::loadModuleSettings($module);
        }

        public static function loadModulePrefs(string $module, ?int $user = null): void
        {
            self::$lastUser = $user;
            \Lotgd\Modules::loadModulePrefs($module, $user);
        }
    }
}

namespace {
    if (!function_exists('load_module_settings')) {
        function load_module_settings(string $module): void
        {
            \Lotgd\Modules\HookHandler::loadModuleSettings($module);
        }
    }
    if (!function_exists('load_module_prefs')) {
        function load_module_prefs(string $module, ?int $user = null): void
        {
            \Lotgd\Modules\HookHandler::loadModulePrefs($module, $user);
        }
    }
}

namespace Lotgd\Tests\Modules\Settings {

    use Lotgd\Tests\Modules\Settings\Stubs\HookHandler;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use Lotgd\Tests\Stubs\DoctrineResult;
    use PHPUnit\Framework\TestCase;
    use Lotgd\Modules\ModuleManager;

/**
 * @group settings
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
    final class ModuleLoadSettingsPrefsTest extends TestCase
    {
        protected function setUp(): void
        {
            if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
                class_alias(HookHandler::class, 'Lotgd\\Modules\\HookHandler');
            }

            HookHandler::$lastUser = null;

            class_exists(Database::class);
            class_exists(\Lotgd\Tests\Stubs\DoctrineBootstrap::class);
            Database::$queryCacheResults = [];
            Database::$lastSql           = '';
            Database::$doctrineConnection = null;
            ModuleManager::setSettings([]);
            ModuleManager::setPrefs([]);
        }

        public function testLoadModuleSettingsAndPrefs(): void
        {
            $module = 'mod';
            $userId = 42;

            Database::$queryCacheResults["modulesettings-$module"] = [
            ['setting' => 'skey', 'value' => 'sval'],
            ];

            $conn = new class extends DoctrineConnection {
                public array $data = [];
                public function executeQuery(string $sql): DoctrineResult
                {
                    $this->queries[] = $sql;
                    return new DoctrineResult($this->data[$sql] ?? []);
                }
            };
            $sql = "SELECT setting,value FROM module_userprefs WHERE modulename='$module' AND userid='$userId'";
            $conn->data[$sql] = [
            ['setting' => 'pkey', 'value' => 'pval'],
            ];
            Database::$doctrineConnection = $conn;

            load_module_settings($module);
            load_module_prefs($module, $userId);

            $module_settings = ModuleManager::settings();
            $module_prefs    = ModuleManager::prefs();
            self::assertSame(['skey' => 'sval'], $module_settings[$module]);
            self::assertSame(['pkey' => 'pval'], $module_prefs[$userId][$module]);
        }

        public function testLoadModulePrefsFallsBackToSessionUser(): void
        {
            $module = 'mod';
            $userId = 42;

            Database::$queryCacheResults["modulesettings-$module"] = [
            ['setting' => 'skey', 'value' => 'sval'],
            ];

            $conn = new class extends DoctrineConnection {
                public array $data = [];
                public function executeQuery(string $sql): DoctrineResult
                {
                    $this->queries[] = $sql;
                    return new DoctrineResult($this->data[$sql] ?? []);
                }
            };
            $sql = "SELECT setting,value FROM module_userprefs WHERE modulename='$module' AND userid='$userId'";
            $conn->data[$sql] = [
            ['setting' => 'pkey', 'value' => 'pval'],
            ];
            Database::$doctrineConnection = $conn;

            global $session;
            $session['user']['acctid'] = $userId;

            load_module_settings($module);
            load_module_prefs($module);

            self::assertNull(HookHandler::$lastUser);
            $module_prefs = ModuleManager::prefs();
            self::assertSame(['pkey' => 'pval'], $module_prefs[$userId][$module]);
        }
    }

}
