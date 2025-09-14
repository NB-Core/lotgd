<?php

declare(strict_types=1);

namespace {
    if (!function_exists('getmicrotime')) {
        function getmicrotime(): float
        {
            return microtime(true);
        }
    }

    function bad_return(string $hookName, array $args)
    {
        return 'not array';
    }
}

namespace Lotgd\Tests\Modules\Hooks {

    use Lotgd\Modules;
    use Lotgd\MySQL\Database;
    use PHPUnit\Framework\TestCase;
    use Lotgd\Util\ScriptName;
    use Lotgd\Modules\ModuleManager;
    use ReflectionClass;

    function modulehook_validation(string $hookName, $args = [], bool $allowInactive = false, $only = false)
    {
        if (!is_array($args)) {
            $where = ModuleManager::getMostRecentModule() ?: ScriptName::current();
            debug("Args parameter to modulehook $hookName from $where is not an array.");
            $args = ['bogus_args' => $args];
        }

        return Modules::hook($hookName, $args, $allowInactive, $only);
    }

/**
 * @group hooks
 */
    final class ModuleHookValidationTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $forms_output;
            $session = ['user' => ['superuser' => 0]];
            $forms_output = '';
        }

        public function testStringArgsProduceDebugAndBogusArray(): void
        {
            global $forms_output;

            Database::$queryCacheResults['hook-sample'] = [];

            $ref  = new ReflectionClass(Modules::class);
            $prop = $ref->getProperty('injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => [], 0 => []]);

            $result = modulehook_validation('sample', 'string');

            unset(Database::$queryCacheResults['hook-sample']);

            self::assertSame(['bogus_args' => 'string'], $result);
            self::assertStringContainsString('Args parameter to modulehook sample', $forms_output);
        }

        public function testNonArrayReturnTriggersWarningAndKeepsArgs(): void
        {
            Database::$queryCacheResults['hook-sample'] = [
            ['modulename' => 'foo', 'location' => 'sample', 'hook_callback' => 'bad_return', 'whenactive' => ''],
            ];

            $ref  = new ReflectionClass(Modules::class);
            $prop = $ref->getProperty('injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => ['foo' => true], 0 => ['foo' => true]]);

            $args = ['foo' => 'bar'];

            $errors = [];
            set_error_handler(function (int $errno, string $errstr) use (&$errors): bool {
                $errors[] = $errstr;
                return true;
            }, E_USER_WARNING);

            $result = modulehook_validation('sample', $args);

            restore_error_handler();

            unset(Database::$queryCacheResults['hook-sample']);

            self::assertSame($args, $result);
            self::assertContains('bad_return did not return an array in the module foo for hook sample.', $errors);
        }

        public function testMissingCallbackTriggersWarningAndContinues(): void
        {
            Database::$queryCacheResults['hook-sample'] = [
            ['modulename' => 'foo', 'location' => 'sample', 'hook_callback' => 'missing_callback', 'whenactive' => ''],
            ];

            $ref  = new ReflectionClass(Modules::class);
            $prop = $ref->getProperty('injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => ['foo' => true], 0 => ['foo' => true]]);

            $args = ['foo' => 'bar'];

            $errors = [];
            set_error_handler(function (int $errno, string $errstr) use (&$errors): bool {
                $errors[] = $errstr;
                return true;
            }, E_WARNING | E_USER_WARNING);

            $result = modulehook_validation('sample', $args);

            restore_error_handler();

            unset(Database::$queryCacheResults['hook-sample']);

            self::assertSame($args, $result);
            self::assertContains('Unknown function missing_callback for hookname sample in module foo.', $errors);
        }
    }

}
