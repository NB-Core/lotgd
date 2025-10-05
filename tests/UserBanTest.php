<?php

declare(strict_types=1);

namespace {
    if (!function_exists('URLEncode')) {
        function URLEncode(string $str): string
        {
            return urlencode($str);
        }
    }

    if (!function_exists('debuglog')) {
        function debuglog(string $message): void
        {
        }
    }
}

namespace Lotgd {
    if (!class_exists(__NAMESPACE__ . '\\Nav')) {
        class Nav
        {
            public static array $links = [];

            public static function add(mixed ...$args): void
            {
                self::$links[] = $args;
            }

            public static function reset(): void
            {
                self::$links = [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\Translator')) {
        class Translator
        {
            public static function getInstance(): self
            {
                return new self();
            }

            public static function translateInline(string $text): string
            {
                return $text;
            }

            public function setSchema(mixed $schema = null): void
            {
            }

            public function sprintfTranslate(string $format, mixed ...$args): string
            {
                return vsprintf($format, $args);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\Output')) {
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

            public function rawOutput(string $text): void
            {
                $this->messages[] = ['raw', [$text]];
            }

            public function output(string $format, mixed ...$args): void
            {
                $this->messages[] = ['output', array_merge([$format], $args)];
            }

            public function outputNotl(string $format, mixed ...$args): void
            {
                $this->messages[] = ['outputNotl', array_merge([$format], $args)];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\DateTime')) {
        class DateTime
        {
            public static function relativeDate(string $date): string
            {
                return $date;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\Cookies')) {
        class Cookies
        {
            public static string $lgi = 'test-session-id';

            public static function getLgi(): string
            {
                return self::$lgi;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\PlayerSearch')) {
        class PlayerSearch
        {
            public function legacyLookup(
                string $search,
                ?array $columns = null,
                ?string $order = null,
                int $exactLimit = 2,
                int $fuzzyLimit = 301
            ): array {
                return ['rows' => [], 'error' => ''];
            }
        }
    }
}

namespace Lotgd\Tests {
    use Lotgd\MySQL\Database;
    use Lotgd\Output;
    use Lotgd\Settings;
    use Lotgd\Tests\Stubs\DummySettings;
    use Lotgd\Tests\Stubs\DoctrineBootstrap;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use PHPUnit\Framework\TestCase;

    final class UserBanTest extends TestCase
    {
        private DoctrineConnection $connection;

        protected function setUp(): void
        {
            parent::setUp();

            require_once __DIR__ . '/Stubs/DoctrineBootstrap.php';
            if (!defined('DATETIME_DATEMAX')) {
                define('DATETIME_DATEMAX', '2159-01-01 00:00:00');
            }
            if (!defined('DATETIME_DATEMIN')) {
                define('DATETIME_DATEMIN', '2000-01-01 00:00:00');
            }

            Database::$doctrineConnection = null;
            Database::$instance = null;
            Database::$mockResults = [];
            Database::$queries = [];
            DoctrineBootstrap::$conn = null;
            Database::$settings_table = [
                'charset'           => 'UTF-8',
                'enabletranslation' => true,
                'collecttexts'      => '',
            ];
            Settings::setInstance(new DummySettings(Database::$settings_table));
            if (method_exists(Output::class, 'reset')) {
                Output::reset();
            }
            if (method_exists(\Lotgd\Nav::class, 'reset')) {
                \Lotgd\Nav::reset();
            }

            $this->connection = Database::getDoctrineConnection();
            $this->connection->queries = [];
            $this->connection->executeStatements = [];
            $this->connection->fetchAllResults = [];
            $this->connection->lastFetchAllParams = [];
            $this->connection->lastFetchAllTypes = [];

            global $_GET, $_POST, $_SERVER, $session;
            $_GET = [];
            $_POST = [];
            $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
            $session = ['user' => ['name' => 'Admin']];
        }

        public function testSavingPermanentBanUsesDatetimeMax(): void
        {
            global $_POST;
            $_POST = [
                'type'     => 'ip',
                'ip'       => '8.8.8.8',
                'duration' => '0',
                'reason'   => 'Testing',
            ];

            Database::$mockResults = [[['acctid' => 42]]];

            $include = static function (): void {
                global $session, $_POST, $_SERVER;

                require __DIR__ . '/../pages/bans/case_saveban.php';
            };
            \Closure::bind($include, null, null)();

            $statement = $this->connection->executeStatements[0] ?? null;
            $this->assertNotNull($statement, 'Expected an INSERT statement to be recorded.');
            $this->assertSame(DATETIME_DATEMAX, $statement['params'][2] ?? null);
        }

        public function testRemoveBanHonoursDatetimeBounds(): void
        {
            global $_GET;
            $_GET = ['duration' => 'forever'];

            $this->connection->fetchAllResults = [[[
                'ipfilter'  => '127.0.0.1',
                'uniqueid'  => 'example',
                'banexpire' => DATETIME_DATEMAX,
                'banreason' => 'Testing',
                'banner'    => 'Admin',
                'lasthit'   => DATETIME_DATEMIN,
            ]]];

            $include = static function (): void {
                global $_GET;

                require __DIR__ . '/../pages/bans/case_removeban.php';
            };
            \Closure::bind($include, null, null)();

            $statement = $this->connection->executeStatements[0] ?? null;
            $this->assertNotNull($statement);
            $this->assertSame(DATETIME_DATEMAX, $statement['params']['max'] ?? null);
            $this->assertSame(DATETIME_DATEMAX, $this->connection->lastFetchAllParams['max'] ?? null);
        }
    }
}
