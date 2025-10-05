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
    if (! class_exists(__NAMESPACE__ . '\\Output', false)) {
        class Output
        {
            private static ?self $instance = null;

            /** @var array<int, array{string, array<int, mixed>}> */
            public array $messages = [];

            public static function getInstance(): self
            {
                return self::$instance ??= new self();
            }

            public static function reset(): void
            {
                self::$instance = null;
            }

            public function output(string $format, mixed ...$args): void
            {
                $this->messages[] = ['output', array_merge([$format], $args)];
            }

            public function outputNotl(string $format, mixed ...$args): void
            {
                $this->messages[] = ['outputNotl', array_merge([$format], $args)];
            }

            public function rawOutput(string $text): void
            {
                $this->messages[] = ['raw', [$text]];
            }
        }
    }

    if (! class_exists(__NAMESPACE__ . '\\Cookies', false)) {
        class Cookies
        {
            public static string $lgi = 'test-session-id';

            public static function getLgi(): string
            {
                return self::$lgi;
            }
        }
    }
}

namespace Lotgd\Tests\Bans {
    use Doctrine\DBAL\ArrayParameterType;
    use Lotgd\MySQL\Database;
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
            \Lotgd\Cookies::$lgi = 'different-session';
            \Lotgd\Output::reset();
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

            $insert = $this->connection->executeStatements[0] ?? null;
            $this->assertNotNull($insert, 'Insert statement should be recorded.');
            $this->assertSame(
                ['Admin "Ω"', $ip, $expectedExpiry, $reason],
                array_values($insert['params'] ?? [])
            );
            $this->assertStringNotContainsString($ip, $insert['sql'] ?? '');
            $this->assertStringNotContainsString($reason, $insert['sql'] ?? '');

            $this->assertSame($ip, $this->connection->lastFetchAllParams['value'] ?? null);

            $update = $this->connection->executeStatements[1] ?? null;
            $this->assertNotNull($update, 'Update statement should be recorded.');
            $this->assertSame([[101, 202]], $update['params'] ?? []);
            $this->assertSame(ArrayParameterType::INTEGER, $update['types'][0] ?? null);
        }
    }
}
