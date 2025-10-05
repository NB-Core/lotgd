<?php

declare(strict_types=1);

namespace {
    if (! function_exists('debuglog')) {
        function debuglog(string $message): void
        {
        }
    }
}

namespace Lotgd {
    if (! class_exists(__NAMESPACE__ . '\\Translator', false)) {
        class Translator
        {
            public static function translate(string $text, string|false|null $schema = false): string
            {
                return $text;
            }

            public static function sprintfTranslate(string $format, mixed ...$args): string
            {
                return vsprintf($format, $args);
            }

            public static function translateInline(string $text): string
            {
                return $text;
            }

            public static function tlbuttonPop(): string
            {
                return '';
            }
        }
    }

}

namespace Lotgd\Tests\Bans {
    use Doctrine\DBAL\ArrayParameterType;
    use Lotgd\MySQL\Database;
    use Lotgd\Settings;
    use Lotgd\Output;
    use Lotgd\Tests\Stubs\DummySettings;
    use Lotgd\Tests\Stubs\DoctrineBootstrap;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use PHPUnit\Framework\TestCase;

    final class SaveBanParameterBindingTest extends TestCase
    {
        private DoctrineConnection $connection;

        protected function setUp(): void
        {
            require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

            Database::$doctrineConnection = null;
            Database::$instance = null;
            DoctrineBootstrap::$conn = null;
            Database::$mockResults = [];
            Database::$settings_table = [
                'charset'           => 'UTF-8',
                'enabletranslation' => true,
                'collecttexts'      => '',
            ];

            Settings::setInstance(new DummySettings(Database::$settings_table));

            $this->connection = Database::getDoctrineConnection();
            $this->connection->queries = [];
            $this->connection->executeStatements = [];
            $this->connection->fetchAllResults = [];
            $this->connection->lastFetchAllParams = [];
            $this->connection->lastFetchAllTypes = [];

            if (! defined('DATETIME_DATEMAX')) {
                define('DATETIME_DATEMAX', '2159-01-01 00:00:00');
            }

            global $_POST, $_GET, $_SERVER, $_COOKIE, $session;
            $_POST = [];
            $_GET = [];
            $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
            $_COOKIE = [];
            $session = ['user' => ['name' => 'Admin "Ω"']];

            $_COOKIE['lgi'] = str_repeat('a', 32);

            $outputReflection = new \ReflectionClass(Output::class);
            if ($outputReflection->hasProperty('instance')) {
                $instance = $outputReflection->getProperty('instance');
                $instance->setAccessible(true);
                $instance->setValue(null, null);
            }
        }

        public function testBanCreationUsesBoundParameters(): void
        {
            global $_POST;

            $ip = "5.6.7.8'Ω";
            $reason = "Because \"Ω\" & friends' meeting";
            $_POST = [
                'type'     => 'ip',
                'ip'       => $ip,
                'duration' => '0',
                'reason'   => $reason,
            ];

            $this->connection->fetchAllResults[] = [
                ['acctid' => 101],
                ['acctid' => 202],
            ];

            $expectedExpiry = DATETIME_DATEMAX;

            $include = static function (): void {
                global $session, $_POST, $_SERVER;

                require __DIR__ . '/../../pages/bans/case_saveban.php';
            };
            \Closure::bind($include, null, null)();

            $bansTable = Database::prefix('bans');
            $accountsTable = Database::prefix('accounts');
            $statements = array_values(array_filter(
                $this->connection->executeStatements,
                static fn (array $statement): bool => isset($statement['sql'])
                    && (str_contains($statement['sql'], $bansTable) || str_contains($statement['sql'], $accountsTable))
            ));

            $insert = $statements[0] ?? null;
            $this->assertNotNull($insert, 'Insert statement should be recorded.');
            $this->assertSame(
                ['Admin "Ω"', $ip, $expectedExpiry, $reason],
                array_values($insert['params'] ?? [])
            );
            $this->assertStringNotContainsString($ip, $insert['sql'] ?? '');
            $this->assertStringNotContainsString($reason, $insert['sql'] ?? '');

            $this->assertSame($ip, $this->connection->lastFetchAllParams['value'] ?? null);

            $update = $statements[1] ?? null;
            $this->assertNotNull($update, 'Update statement should be recorded.');
            $this->assertSame([[101, 202]], $update['params'] ?? []);
            $this->assertSame(ArrayParameterType::INTEGER, $update['types'][0] ?? null);
        }
    }
}
