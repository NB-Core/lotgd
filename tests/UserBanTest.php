<?php

declare(strict_types=1);

namespace {
    if (!function_exists('httpget')) {
        function httpget(string $name): string
        {
            return $_GET[$name] ?? '';
        }
    }
    if (!function_exists('httppost')) {
        function httppost(string $name): string
        {
            return $_POST[$name] ?? '';
        }
    }
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
}

namespace Lotgd\Tests {
    use Lotgd\MySQL\Database;
    use PHPUnit\Framework\TestCase;

    final class UserSaveBanTest extends TestCase
    {
        protected function setUp(): void
        {
            $mockDb = new class {
                public array $queries = [];
                public function query(string $sql): array
                {
                    $this->queries[] = $sql;
                    return [];
                }
            };
            \Lotgd\MySQL\Database::$instance = $mockDb;
            global $_POST, $_SERVER;
            $GLOBALS['session'] = ['user' => ['name' => 'Admin']];
            $_POST   = [
                'type'     => 'ip',
                'ip'       => '8.8.8.8',
                'duration' => '0',
                'reason'   => 'Test',
            ];
            $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
            $GLOBALS['output'] = new class {
                public function output(string $format, ...$args): void
                {
                }
                public function outputNotl(string $format, ...$args): void
                {
                }
            };
        }

        public function testPermanentBanUsesDatetimeDatemin(): void
        {
            $include = function (): void {
                global $session, $_POST, $_SERVER, $output;
                require __DIR__ . '/../pages/user/user_saveban.php';
            };
            \Closure::bind($include, null, null)();
            $queries = Database::getInstance()->queries;
            $this->assertStringContainsString('"' . DATETIME_DATEMIN . '"', $queries[0] ?? '');
        }
    }

    final class UserRemoveBanTest extends TestCase
    {
        protected function setUp(): void
        {
            $mockDb = new class {
                public array $queries = [];
                public function query(string $sql): array
                {
                    $this->queries[] = $sql;
                    return [];
                }
            };
            \Lotgd\MySQL\Database::$instance = $mockDb;
            global $_GET, $_POST;
            $_GET = [];
            $_POST = [];
            $GLOBALS['output'] = new class {
                public function rawOutput(string $text): void
                {
                }
                public function output(string $format, ...$args): void
                {
                }
                public function outputNotl(string $format, ...$args): void
                {
                }
            };
        }

        public function testQueriesUseDatetimeDatemin(): void
        {
            $include = function (): void {
                global $_GET, $_POST, $output;
                require __DIR__ . '/../pages/user/user_removeban.php';
            };
            \Closure::bind($include, null, null)();
            $queries = Database::getInstance()->queries;
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString(DATETIME_DATEMIN, $queries[0] ?? '');
            $this->assertStringContainsString(DATETIME_DATEMIN, $queries[1] ?? '');
        }
    }
}
