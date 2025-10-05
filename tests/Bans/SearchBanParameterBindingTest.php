<?php

declare(strict_types=1);

namespace {
    if (!function_exists('URLEncode')) {
        function URLEncode(string $str): string
        {
            return urlencode($str);
        }
    }

    if (!function_exists('relativedate')) {
        function relativedate(string $date): string
        {
            return $date;
        }
    }

    if (!function_exists('httppost')) {
        function httppost(string $name): string
        {
            return $_POST[$name] ?? '';
        }
    }
}

namespace Lotgd {
    if (!class_exists(__NAMESPACE__ . '\\Nav')) {
        class Nav
        {
            public static function add(mixed ...$args): void
            {
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

            public function sprintfTranslate(string $format, ...$args): string
            {
                return vsprintf($format, $args);
            }

            public function setSchema(mixed $schema = null): void
            {
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\Output')) {
        class Output
        {
            private static ?self $instance = null;

            public static function getInstance(): self
            {
                return self::$instance ??= new self();
            }

            public function rawOutput(string $text): void
            {
            }

            public function output(string $format, mixed ...$args): void
            {
            }

            public function outputNotl(string $format, mixed ...$args): void
            {
            }

            public function getRawOutput(): string
            {
                return '';
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

namespace Lotgd\Tests\Bans {
    use Doctrine\DBAL\ParameterType;
    use Lotgd\MySQL\Database;
    use Lotgd\Tests\Stubs\DoctrineBootstrap;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use PHPUnit\Framework\TestCase;

    final class SearchBanParameterBindingTest extends TestCase
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
            $this->connection->executeQueryParams = [];
            $this->connection->lastFetchAllParams = [];
            $this->connection->lastFetchAllTypes = [];
            $this->connection->lastFetchAssociativeParams = [];
            $this->connection->lastFetchAssociativeTypes = [];
            $this->connection->fetchAssociativeResults = [];
            $this->connection->fetchAllResults = [];
            $this->connection->executeStatements = [];
            $this->connection->lastExecuteStatementParams = [];
            $this->connection->lastExecuteStatementTypes = [];
            if (!defined('DATETIME_DATEMAX')) {
                define('DATETIME_DATEMAX', '2159-01-01 00:00:00');
            }
            if (!defined('DATETIME_DATEMIN')) {
                define('DATETIME_DATEMIN', '2000-01-01 00:00:00');
            }
            global $_POST, $_GET;
            $_POST = [];
            $_GET = [];
            $GLOBALS['output'] = \Lotgd\Output::getInstance();
        }

        public function testCaseSearchBanBindsIpAndId(): void
        {
            $ip = "10.0.0.1'; DROP TABLE accounts; --";
            $id = "abc\" OR \"1\"=\"1";
            $this->connection->fetchAssociativeResults[] = [
                'lastip' => $ip,
                'uniqueid' => $id,
            ];
            $this->connection->fetchAllResults[] = [[
                'ipfilter' => '10.0.0.1',
                'uniqueid' => 'abc',
                'banner' => 'Admin',
                'banexpire' => DATETIME_DATEMAX,
                'banreason' => 'Testing',
                'lasthit' => '2024-01-01 00:00:00',
            ]];
            $_POST['target'] = '42';

            $include = static function (): void {
                require __DIR__ . '/../../pages/bans/case_searchban.php';
            };
            \Closure::bind($include, null, null)();

            $this->assertSame(['acctid' => 42], $this->connection->lastFetchAssociativeParams);
            $this->assertSame(
                ParameterType::INTEGER,
                $this->connection->lastFetchAssociativeTypes['acctid'] ?? null
            );

            $params = $this->connection->lastFetchAllParams;
            $this->assertSame('%' . $ip . '%', $params['ipfilter'] ?? null);
            $this->assertSame('%' . $id . '%', $params['uniqueid'] ?? null);
            $types = $this->connection->lastFetchAllTypes;
            $this->assertSame(ParameterType::STRING, $types['ipfilter'] ?? null);
            $this->assertSame(ParameterType::STRING, $types['uniqueid'] ?? null);

            $selectSql = $this->connection->queries[1] ?? '';
            $this->assertStringNotContainsString($ip, $selectSql);
            $this->assertStringNotContainsString($id, $selectSql);
        }

        public function testUserSearchBanBindsIpAndId(): void
        {
            $ip = "192.168.0.1' --";
            $id = "xyz\"; DROP";
            $this->connection->fetchAssociativeResults[] = [
                'lastip' => $ip,
                'uniqueid' => $id,
            ];
            $this->connection->fetchAllResults[] = [[
                'ipfilter' => '192.168.0.1',
                'uniqueid' => 'xyz',
                'banner' => 'Admin',
                'banexpire' => DATETIME_DATEMAX,
                'banreason' => 'Testing',
                'lasthit' => '2024-01-01 00:00:00',
            ]];
            $_POST['target'] = '99';

            $include = static function (): void {
                $output = \Lotgd\Output::getInstance();
                require __DIR__ . '/../../pages/user/user_searchban.php';
            };
            \Closure::bind($include, null, null)();

            $this->assertSame(['acctid' => 99], $this->connection->lastFetchAssociativeParams);
            $this->assertSame(
                ParameterType::INTEGER,
                $this->connection->lastFetchAssociativeTypes['acctid'] ?? null
            );

            $params = $this->connection->lastFetchAllParams;
            $this->assertSame('%' . $ip . '%', $params['ipfilter'] ?? null);
            $this->assertSame('%' . $id . '%', $params['uniqueid'] ?? null);

            $types = $this->connection->lastFetchAllTypes;
            $this->assertSame(ParameterType::STRING, $types['ipfilter'] ?? null);
            $this->assertSame(ParameterType::STRING, $types['uniqueid'] ?? null);

            $selectSql = $this->connection->queries[1] ?? '';
            $this->assertStringNotContainsString($ip, $selectSql);
            $this->assertStringNotContainsString($id, $selectSql);
        }

        public function testRemoveBanUsesDateParameters(): void
        {
            $this->connection->fetchAllResults[] = [[
                'ipfilter' => '10.0.0.2',
                'uniqueid' => 'def',
                'banner' => 'Admin',
                'banexpire' => DATETIME_DATEMAX,
                'banreason' => 'Testing',
                'lasthit' => '2024-01-01 00:00:00',
            ]];

            $include = static function (): void {
                $output = \Lotgd\Output::getInstance();
                require __DIR__ . '/../../pages/bans/case_removeban.php';
            };
            \Closure::bind($include, null, null)();

            $delete = $this->connection->executeStatements[0] ?? null;
            $this->assertNotNull($delete);
            $this->assertArrayHasKey('now', $delete['params']);
            $this->assertArrayHasKey('max', $delete['params']);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                $delete['params']['now']
            );
            $this->assertSame(DATETIME_DATEMAX, $delete['params']['max']);
            $this->assertSame(ParameterType::STRING, $delete['types']['now'] ?? null);
            $this->assertSame(ParameterType::STRING, $delete['types']['max'] ?? null);

            $params = $this->connection->lastFetchAllParams;
            $this->assertArrayHasKey('max', $params);
            $this->assertArrayHasKey('limit', $params);
            $this->assertSame(DATETIME_DATEMAX, $params['max']);

            $types = $this->connection->lastFetchAllTypes;
            $this->assertSame(ParameterType::STRING, $types['limit'] ?? null);
            $this->assertSame(ParameterType::STRING, $types['max'] ?? null);
        }
    }
}
