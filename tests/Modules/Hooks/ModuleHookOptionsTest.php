<?php

declare(strict_types=1);

namespace {
    function a_sample(string $hookName, array $args): array
    {
        $args['a'] = 'A';
        return $args;
    }

    function b_sample(string $hookName, array $args): array
    {
        $args['b'] = 'B';
        return $args;
    }

    function getmicrotime(): float
    {
        return microtime(true);
    }
}

namespace Lotgd\Tests\Modules\Hooks {

    use Lotgd\Modules;
    use Lotgd\Modules\HookHandler;
    use Lotgd\MySQL\Database;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    function modulehook_options(string $hookName, array $args = [], bool $allowInactive = false, $only = false): array
    {
        return HookHandler::hook($hookName, $args, $allowInactive, $only);
    }

/**
 * @group hooks
 */
    final class ModuleHookOptionsTest extends TestCase
    {
        private array $hooksAll;
        private array $hooksActive;

        protected function setUp(): void
        {
            global $session;
            $session = ['user' => ['superuser' => 0]];

            $this->hooksAll = [
            ['modulename' => 'a', 'location' => 'sample', 'hook_callback' => 'a_sample', 'whenactive' => ''],
            ['modulename' => 'b', 'location' => 'sample', 'hook_callback' => 'b_sample', 'whenactive' => ''],
            ];
            $this->hooksActive = [$this->hooksAll[0]];

            $ref  = new ReflectionClass(Modules::class);
            $prop = $ref->getProperty('injectedModules');
            $prop->setAccessible(true);
            $prop->setValue(null, [1 => ['a' => true, 'b' => true], 0 => ['a' => true]]);
        }

        public function testOnlySpecificModuleIsMerged(): void
        {
            Database::$queryCacheResults['hook-sample'] = $this->hooksAll;

            $result = modulehook_options('sample', [], false, 'a');

            $this->assertSame(['a' => 'A'], $result);
        }

        public function testInactiveModulesRunWhenAllowed(): void
        {
            Database::$queryCacheResults['hook-sample'] = $this->hooksActive;
            $activeResult = modulehook_options('sample', []);
            $this->assertSame(['a' => 'A'], $activeResult);

            Database::$queryCacheResults['hook-sample'] = $this->hooksAll;
            $result = modulehook_options('sample', [], true);

            $this->assertSame(['a' => 'A', 'b' => 'B'], $result);
        }

        public function testReturnsArrayWhenNoModules(): void
        {
            Database::$queryCacheResults['hook-empty'] = [];

            $result = modulehook_options('empty', []);

            $this->assertIsArray($result);
            $this->assertSame([], $result);
        }
    }

}
