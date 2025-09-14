<?php

declare(strict_types=1);

namespace Lotgd {
    if (!function_exists(__NAMESPACE__ . '\\getmicrotime')) {
        function getmicrotime(): float
        {
            return microtime(true);
        }
    }
}

namespace Lotgd\Tests\Modules\Hooks {

    use Lotgd\Modules;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

    function modulehook_block(string $name, array $args = [], bool $allowinactive = false, $only = false): array
    {
        return Modules::hook($name, $args, $allowinactive, $only);
    }

/**
 * @group hooks
 */
    final class BlockModuleTest extends TestCase
    {
        private string $moduleFile;

        protected function setUp(): void
        {
            $this->moduleFile = dirname(__DIR__, 3) . '/modules/foo.php';

            file_put_contents($this->moduleFile, <<<'MODULE'
<?php

declare(strict_types=1);

function foo_getmoduleinfo(): array
{
    return [
        'name' => 'Foo',
        'version' => '1.0',
        'author' => 'Test',
        'category' => 'Test',
        'download' => '',
        'description' => '',
        'requires' => [],
    ];
}

function foo_install(): bool
{
    return true;
}

function foo_uninstall(): bool
{
    return true;
}

function foo_dohook(string $hookname, array $args): array
{
    if ($hookname === 'test') {
        $args['foo'] = true;
    }

    return $args;
}
MODULE
            );

            $filemoddate = date('Y-m-d H:i:s', filemtime($this->moduleFile));

            Database::$queryCacheResults['inject-foo'] = [
            [
                'active' => 1,
                'filemoddate' => $filemoddate,
                'infokeys' => '|name|version|author|category|description|download|requires|',
                'version' => '1.0',
            ],
            ];

            Database::$queryCacheResults['hook-test'] = [
            [
                'modulename' => 'foo',
                'location' => 'test',
                'hook_callback' => 'foo_dohook',
                'whenactive' => '',
            ],
            ];
        }

        protected function tearDown(): void
        {
            unlink($this->moduleFile);
            unset(Database::$queryCacheResults['inject-foo'], Database::$queryCacheResults['hook-test']);
            Modules::unblock('foo');
        }

        public function testBlockAndUnblockModule(): void
        {
            Modules::block('foo');
            self::assertTrue(Modules::isModuleBlocked('foo'));

            $blocked = modulehook_block('test', []);
            self::assertArrayNotHasKey('foo', $blocked);

            Modules::unblock('foo');
            self::assertFalse(Modules::isModuleBlocked('foo'));

            $unblocked = modulehook_block('test', []);
            self::assertArrayHasKey('foo', $unblocked);
            self::assertTrue($unblocked['foo']);
        }
    }
}
