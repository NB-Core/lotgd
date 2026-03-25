<?php

declare(strict_types=1);

namespace {
    if (!function_exists('modulehook')) {
        /**
         * Minimal modulehook shim for isolated page include tests.
         *
         * @param array<mixed> $args
         * @return array<mixed>
         */
        function modulehook(string $hookname, array $args = [], bool $allowinactive = false, string $modulename = ''): array
        {
            return $args;
        }
    }

    if (!function_exists('httpset')) {
        function httpset(string $var, mixed $value, bool $force = false): void
        {
        }
    }
}

namespace Lotgd {
    if (!class_exists(__NAMESPACE__ . '\\Redirect', false)) {
        class Redirect
        {
            public static function redirect(string $location, string|bool $reason = false): void
            {
            }
        }
    }
}

namespace Lotgd\Tests\User {

    use Doctrine\DBAL\ParameterType;
    use Lotgd\MySQL\Database;
    use Lotgd\Tests\Stubs\DoctrineBootstrap;
    use PHPUnit\Framework\TestCase;

    final class UserLegacyHttpMigrationTest extends TestCase
    {
        protected function setUp(): void
        {
            require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';
            Database::$doctrineConnection = null;
            Database::$instance = null;
            DoctrineBootstrap::$conn = null;
            Database::$mockResults = [];
            $_GET = [];
            $_POST = [];
            $GLOBALS['output'] = new class {
                public function outputNotl(string $format, mixed ...$args): void
                {
                }

                public function output(string $format, mixed ...$args): void
                {
                }
            };
        }

        public function testUserDelbanUsesRawHttpAndTypedBoundParameters(): void
        {
            $_GET['ipfilter'] = "10.0.0.1' OR 1=1 --";
            $_GET['uniqueid'] = 'abc"xyz';

            $include = static function (): void {
                require __DIR__ . '/../../pages/user/user_delban.php';
            };
            $include();

            $conn = Database::getDoctrineConnection();
            $statement = $conn->executeStatements[0] ?? null;
            $this->assertIsArray($statement);
            $this->assertSame($_GET['ipfilter'], $statement['params']['ip'] ?? null);
            $this->assertSame($_GET['uniqueid'], $statement['params']['id'] ?? null);
            $this->assertSame(ParameterType::STRING, $statement['types']['ip'] ?? null);
            $this->assertSame(ParameterType::STRING, $statement['types']['id'] ?? null);
        }

        public function testUserSavemoduleUsesHttpClassAndParameterizedReplace(): void
        {
            $_GET['userid'] = '42';
            $_GET['module'] = 'samplemodule';
            $_POST = ['display_name' => "O'Reilly"];

            $include = static function (): void {
                $output = $GLOBALS['output'];
                require __DIR__ . '/../../pages/user/user_savemodule.php';
            };
            $include();

            $conn = Database::getDoctrineConnection();
            $statement = $conn->executeStatements[0] ?? null;
            $this->assertIsArray($statement);
            $this->assertStringContainsString('VALUES (:module,:userid,:setting,:value)', $statement['sql']);
            $this->assertSame("O'Reilly", $statement['params']['value'] ?? null);
            $this->assertSame(ParameterType::INTEGER, $statement['types']['userid'] ?? null);
        }
    }
}
