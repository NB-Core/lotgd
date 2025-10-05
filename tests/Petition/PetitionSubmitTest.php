<?php

declare(strict_types=1);

namespace {
    if (! class_exists('Lotgd\\Translator', false)) {
        class Translator
        {
            public static function getInstance(): self
            {
                return new self();
            }

            public function setSchema(string|false|null $schema = false): void
            {
            }

            public static function translate(string $text, string|false|null $schema = false): string
            {
                return $text;
            }

            public static function translateInline(string $text): string
            {
                return $text;
            }

            public static function sprintfTranslate(string $format, mixed ...$args): string
            {
                return vsprintf($format, $args);
            }

            public static function translatorSetup(): void
            {
            }

            public static function tlbuttonPop(): string
            {
                return '';
            }
        }
    }

    if (! class_exists('Lotgd\\Page\\Header', false)) {
        $headerStub = new class {
            public static function popupHeader(...$args): void
            {
            }
        };

        class_alias(get_class($headerStub), 'Lotgd\\Page\\Header');
    }
}

namespace Lotgd\Tests\Petition {
    use Doctrine\DBAL\ParameterType;
    use Lotgd\MySQL\Database;
    use Lotgd\Settings;
    use Lotgd\Tests\Stubs\DummySettings;
    use Lotgd\Tests\Stubs\DoctrineBootstrap;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use PHPUnit\Framework\TestCase;

    final class PetitionSubmitTest extends TestCase
    {
        private DoctrineConnection $connection;

        protected function setUp(): void
        {
            parent::setUp();

            require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

            Database::$doctrineConnection = null;
            Database::$instance = null;
            DoctrineBootstrap::$conn = null;
            Database::$mockResults = [];
            Database::$queries = [];
            Database::$queryCacheResults = [];
            Database::$settings_table = [
                'charset'           => 'UTF-8',
                'enabletranslation' => true,
                'collecttexts'      => '',
                'emailpetitions'    => 0,
                'petition_types'    => 'General',
                'serverurl'         => 'http://example.com/',
                'gameadminemail'    => 'admin@example.com',
            ];

            Settings::setInstance(new DummySettings(Database::$settings_table));

            $this->connection = Database::getDoctrineConnection();
            $this->connection->queries = [];
            $this->connection->executeStatements = [];
            $this->connection->executeQueryParams = [];
            $this->connection->executeQueryTypes = [];
            $this->connection->lastExecuteStatementParams = [];
            $this->connection->lastExecuteStatementTypes = [];
            $this->connection->countResults = [0];

            Database::$queryCacheResults['hook-addpetition'] = [];
            Database::$queryCacheResults['hook-header-popup'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['settings']);
            Settings::setInstance(null);

            parent::tearDown();
        }

        public function testPetitionSubmissionBindsParameters(): void
        {
            global $session, $settings, $output;

            $session = [
                'user' => [
                    'acctid'       => 42,
                    'password'     => 'hunter2',
                    'superuser'    => 0,
                    'name'         => 'TestUser',
                    'emailaddress' => 'tester@example.com',
                ],
            ];
            $settings = Settings::getInstance();
            $GLOBALS['settings'] = $settings;

            $output = new class {
                public array $buffer = [];

                public function output(string $format, mixed ...$args): void
                {
                    $this->buffer[] = vsprintf($format, $args);
                }

                public function outputNotl(string $format, mixed ...$args): void
                {
                    $this->buffer[] = vsprintf($format, $args);
                }

                public function rawOutput(string $text): void
                {
                    $this->buffer[] = $text;
                }
            };

            $_GET = [];
            $_POST = [];
            $_COOKIE = [];
            $_SERVER = [];

            $body = "Quotes ' and \" with emoji 😀 and kana かな";
            $_POST = [
                'problem'     => 'Testing petition submission',
                'description' => $body,
                'abuse'       => 'no',
            ];
            $_COOKIE['lgi'] = str_repeat('b', 32);
            $_SERVER['REMOTE_ADDR'] = '203.0.113.25';
            $_SERVER['SERVER_NAME'] = 'example.com';
            $_SERVER['SERVER_PORT'] = 80;
            $_SERVER['REQUEST_URI'] = '/petition.php';

            $include = static function (): void {
                global $session, $settings, $output, $_POST, $_SERVER, $_COOKIE;

                require __DIR__ . '/../../pages/petition/petition_default.php';
            };
            \Closure::bind($include, null, null)();

            $params = $this->connection->lastFetchAssociativeParams ?? [];
            self::assertSame('203.0.113.%', $params['iplike'] ?? null);
            self::assertSame(str_repeat('b', 32), $params['cookie'] ?? null);
            self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $params['cutoff'] ?? '');

            $selectTypes = $this->connection->lastFetchAssociativeTypes ?? [];
            self::assertSame(ParameterType::STRING, $selectTypes['iplike'] ?? null);
            self::assertSame(ParameterType::STRING, $selectTypes['cookie'] ?? null);
            self::assertSame(ParameterType::STRING, $selectTypes['cutoff'] ?? null);

            $insert = $this->connection->executeStatements[0] ?? [];
            self::assertNotNull($insert, 'Petition insert should be logged.');

            $insertParams = $insert['params'] ?? [];
            self::assertSame(42, $insertParams['author'] ?? null);
            self::assertStringContainsString($body, $insertParams['body'] ?? '');
            self::assertStringContainsString('Session:', $insertParams['pageinfo'] ?? '');
            self::assertSame('203.0.113.25', $insertParams['ip'] ?? null);
            self::assertSame(str_repeat('b', 32), $insertParams['cookie'] ?? null);

            self::assertStringNotContainsString($body, $insert['sql'] ?? '');
            self::assertStringNotContainsString(str_repeat('b', 32), $insert['sql'] ?? '');

            $insertTypes = $insert['types'] ?? [];
            self::assertSame(ParameterType::INTEGER, $insertTypes['author'] ?? null);
            self::assertSame(ParameterType::STRING, $insertTypes['body'] ?? null);
            self::assertSame(ParameterType::STRING, $insertTypes['pageinfo'] ?? null);
            self::assertSame(ParameterType::STRING, $insertTypes['ip'] ?? null);
            self::assertSame(ParameterType::STRING, $insertTypes['cookie'] ?? null);
        }
    }
}
