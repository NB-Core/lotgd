<?php

declare(strict_types=1);

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

namespace Lotgd\Tests\Modules {

use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DoctrineResult;
use PHPUnit\Framework\TestCase;

final class ModuleLoadSettingsPrefsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        class_exists(\Lotgd\Tests\Stubs\DoctrineBootstrap::class);
        Database::$queryCacheResults = [];
        Database::$lastSql           = '';
        Database::$doctrineConnection = null;
        global $module_settings, $module_prefs;
        $module_settings = [];
        $module_prefs    = [];
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

        global $module_settings, $module_prefs;
        self::assertSame(['skey' => 'sval'], $module_settings[$module]);
        self::assertSame(['pkey' => 'pval'], $module_prefs[$userId][$module]);
    }
}

}
