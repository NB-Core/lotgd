<?php

declare(strict_types=1);

namespace {
    if (!function_exists('set_module_pref')) {
        function set_module_pref(string $name, mixed $value, ?string $module = null, ?int $user = null): void
        {
            \Lotgd\Modules\HookHandler::setModulePref($name, $value, $module, $user);
        }
    }
    if (!function_exists('get_module_pref')) {
        function get_module_pref(string $name, ?string $module = null, ?int $user = null)
        {
            return \Lotgd\Modules\HookHandler::getModulePref($name, $module, $user);
        }
    }
    if (!function_exists('increment_module_pref')) {
        function increment_module_pref(string $name, int|float $value = 1, ?string $module = null, ?int $user = null): void
        {
            \Lotgd\Modules\HookHandler::incrementModulePref($name, $value, $module, $user);
        }
    }
    if (!function_exists('clear_module_pref')) {
        function clear_module_pref(string $name, ?string $module = null, ?int $user = null): void
        {
            \Lotgd\Modules\HookHandler::clearModulePref($name, $module, $user);
        }
    }
    if (!function_exists('get_all_module_prefs')) {
        function get_all_module_prefs(?string $module = null, ?int $user = null): array
        {
            return \Lotgd\Modules\HookHandler::getAllModulePrefs($module, $user);
        }
    }
}

namespace Lotgd\Tests\Modules\Prefs {

    use Lotgd\Modules;
    use Lotgd\Modules\ModuleManager;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use Lotgd\Tests\Stubs\DoctrineResult;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

/**
 * @group prefs
 */
    final class ModulePrefsTest extends TestCase
    {
        private string $moduleFile;

        protected function setUp(): void
        {
            class_exists(Database::class);
            Database::$queryCacheResults = [];
            Database::$lastSql           = '';
            $conn                        = new class extends DoctrineConnection {
                public function executeQuery(string $sql): DoctrineResult
                {
                    $this->queries[] = $sql;
                    return new DoctrineResult([]);
                }
            };
            Database::$doctrineConnection        = $conn;
            \Lotgd\Doctrine\Bootstrap::$conn = $conn;

            global $session;
            $session         = ['user' => ['acctid' => 1, 'loggedin' => true]];
            ModuleManager::setPrefs([]);
            ModuleManager::setMostRecentModule('');

            $ref  = new ReflectionClass(Modules::class);
            $prop = $ref->getProperty('injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => [], 0 => []]);
            $prop = $ref->getProperty('modulehookQueries');
            $prop->setAccessible(true);
            $prop->setValue(null, []);

            $this->moduleFile = dirname(__DIR__, 3) . '/modules/modA.php';
            file_put_contents($this->moduleFile, <<<'MODULE'
<?php

declare(strict_types=1);

function modA_getmoduleinfo(): array
{
    return [
        'name' => 'modA',
        'version' => '1.0',
        'author' => 'Test',
        'category' => 'Test',
        'download' => '',
        'description' => '',
        'requires' => [],
        'prefs' => [
            'flag'  => 'Flag,bool|off',
            'count' => 'Count,int|0',
        ],
    ];
}

function modA_install(): bool
{
    return true;
}

function modA_uninstall(): bool
{
    return true;
}
MODULE
            );

            $filemoddate = date('Y-m-d H:i:s', filemtime($this->moduleFile));
            Database::$queryCacheResults['inject-modA'] = [
            [
                'active'     => 1,
                'filemoddate' => $filemoddate,
                'infokeys'   => '|name|version|author|category|description|download|requires|prefs|',
                'version'    => '1.0',
            ],
            ];
        }

        protected function tearDown(): void
        {
            unlink($this->moduleFile);
            unset(Database::$queryCacheResults['inject-modA']);
            Database::$doctrineConnection = null;
            \Lotgd\Doctrine\Bootstrap::$conn = null;
        }

        /**
         * @param callable    $set
         * @param callable    $get
         * @param callable    $inc
         * @param callable    $clear
         * @param string|null $module
         * @param int|null    $user
         * @param mixed       $expected
         */
        private function runLifecycle(callable $set, callable $get, callable $inc, callable $clear, ?string $module, false|int|null $user, $expected): void
        {
            if ($user === false) {
                $user = null;
            }

            $set('flag', 'on', $module, $user);
            $set('count', 0, $module, $user);
            self::assertSame('on', $get('flag', $module, $user));

            $inc('count', 1, $module, $user);
            $inc('count', 1, $module, $user);
            $inc('count', 1, $module, $user);
            self::assertSame(3.0, $get('count', $module, $user));

            $clear('flag', $module, $user);
            self::assertSame($expected, $get('flag', $module, $user));
        }

        public function testWrapperExplicitUserAndModule(): void
        {
            $this->runLifecycle('set_module_pref', 'get_module_pref', 'increment_module_pref', 'clear_module_pref', 'modA', 1, 'off');
        }

        public function testWrapperFallbackUser(): void
        {
            $this->runLifecycle('set_module_pref', 'get_module_pref', 'increment_module_pref', 'clear_module_pref', 'modA', null, 'off');
        }

        public function testWrapperEmptyModule(): void
        {
            $this->runLifecycle('set_module_pref', 'get_module_pref', 'increment_module_pref', 'clear_module_pref', '', 1, null);
        }

        public function testWrapperFalseUser(): void
        {
            $this->runLifecycle('set_module_pref', 'get_module_pref', 'increment_module_pref', 'clear_module_pref', 'modA', false, 'off');
        }

        public function testClassExplicitUserAndModule(): void
        {
            $this->runLifecycle([Modules::class, 'setModulePref'], [Modules::class, 'getModulePref'], [Modules::class, 'incrementModulePref'], [Modules::class, 'clearModulePref'], 'modA', 1, 'off');
        }

        public function testClassFallbackUser(): void
        {
            $this->runLifecycle([Modules::class, 'setModulePref'], [Modules::class, 'getModulePref'], [Modules::class, 'incrementModulePref'], [Modules::class, 'clearModulePref'], 'modA', null, 'off');
        }

        public function testClassEmptyModule(): void
        {
            $this->runLifecycle([Modules::class, 'setModulePref'], [Modules::class, 'getModulePref'], [Modules::class, 'incrementModulePref'], [Modules::class, 'clearModulePref'], '', 1, null);
        }

        public function testGetAllModulePrefs(): void
        {
            set_module_pref('flag', 'on', 'modA', 1);
            set_module_pref('count', 0, 'modA', 1);
            increment_module_pref('count', 1, 'modA', 1);
            increment_module_pref('count', 1, 'modA', 1);

            $prefs = get_all_module_prefs('modA', 1);

            self::assertSame([
            'flag' => 'on',
            'count' => 2.0,
            ], $prefs);
        } // end testGetAllModulePrefs

        public function testIncrementModulePrefWithNegativeAndFractionalValues(): void
        {
            foreach ([-1.0, 1.5] as $increment) {
                set_module_pref('count', 0, 'modA', 1);
                increment_module_pref('count', $increment, 'modA', 1);

                self::assertSame($increment, get_module_pref('count', 'modA', 1), "increment {$increment}");
            }
        }

        public function testClassFalseUser(): void
        {
            $this->runLifecycle([Modules::class, 'setModulePref'], [Modules::class, 'getModulePref'], [Modules::class, 'incrementModulePref'], [Modules::class, 'clearModulePref'], 'modA', false, 'off');
        }
    }
}
