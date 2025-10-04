<?php

declare(strict_types=1);

namespace {
    if (!function_exists('httpget')) {
        function httpget(string $name)
        {
            return $_GET[$name] ?? '';
        }
    }

    if (!function_exists('httpset')) {
        function httpset(string $name, $value, bool $persistent = false): void
        {
            $_GET[$name] = $value;
        }
    }

    if (!function_exists('popup_footer')) {
        function popup_footer(): void
        {
        }
    }

}

namespace Lotgd\Tests {

    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

    final class MailWriteComposeTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $forms_output, $output;
            $session = ['user' => ['acctid' => 1, 'prefs' => []]];
            $forms_output = '';
            $output = new class {
                public function appoencode($data, $priv = false)
                {
                    return $data;
                }
            };
            $_GET = [];
            $_POST = [];
            Database::$mockResults = [];
            Database::resetDoctrineConnection();
            if (! defined('LOTGD_MAIL_WRITE_AUTORUN')) {
                define('LOTGD_MAIL_WRITE_AUTORUN', false);
            }
            static $loaded = false;
            if (! $loaded) {
                require_once __DIR__ . '/../pages/mail/case_write.php';
                $loaded = true;
            }
            $forms_output = '';
        }

        public function testRecipientDropdownShownForPartialNames(): void
        {
            global $forms_output;
            $_POST['to'] = 'ja';
            $test_accounts_query_result = [
                ['acctid' => 10, 'login' => 'john', 'name' => 'John', 'superuser' => 0],
                ['acctid' => 11, 'login' => 'jane', 'name' => 'Jane', 'superuser' => 0],
            ];
            $conn = Database::getDoctrineConnection();
            $conn->fetchAllResults = [$test_accounts_query_result];
            Database::$mockResults = array_fill(0, 4, []);
            \mailWrite();
            $this->assertGreaterThanOrEqual(2, count($conn->executeQueryParams));
            $expectedPattern = $this->buildWildcardPattern('ja', 'UTF-8');
            $this->assertSame($expectedPattern, $conn->executeQueryParams[1]['pattern']);
        }

        public function testFallbackSearchHandlesQuotedNames(): void
        {
            global $forms_output;

            $_POST['to'] = "O'";
            $test_accounts_query_result = [
                [
                    'acctid'    => 20,
                    'login'     => 'oconnor',
                    'name'      => "Shaun \"Quote\" O'Connor",
                    'superuser' => 0,
                ],
            ];

            $conn = Database::getDoctrineConnection();
            $conn->fetchAllResults = [$test_accounts_query_result];
            Database::$mockResults = array_fill(0, 4, []);

            \mailWrite();

            $this->assertGreaterThanOrEqual(2, count($conn->executeQueryParams));

            $expectedPattern = $this->buildWildcardPattern("O'", 'UTF-8');
            $this->assertSame($expectedPattern, $conn->executeQueryParams[1]['pattern']);
        }

        public function testFallbackSearchHandlesMultibyteNames(): void
        {
            global $forms_output;

            $_POST['to'] = 'さく';
            $test_accounts_query_result = [
                [
                    'acctid'    => 30,
                    'login'     => 'sakura',
                    'name'      => 'さくら"🌸"',
                    'superuser' => 0,
                ],
            ];

            $conn = Database::getDoctrineConnection();
            $conn->fetchAllResults = [$test_accounts_query_result];
            Database::$mockResults = [[], []];

            \mailWrite();

            $this->assertGreaterThanOrEqual(2, count($conn->executeQueryParams));

            $expectedPattern = $this->buildWildcardPattern('さく', 'UTF-8');
            $this->assertSame($expectedPattern, $conn->executeQueryParams[1]['pattern']);
        }

        private function buildWildcardPattern(string $value, string $charset): string
        {
            $pattern = '%';

            if (function_exists('mb_strlen')) {
                $length = mb_strlen($value, $charset);

                if ($length === false) {
                    $length = strlen($value);
                    for ($i = 0; $i < $length; ++$i) {
                        $pattern .= $value[$i] . '%';
                    }
                } else {
                    for ($i = 0; $i < $length; ++$i) {
                        $pattern .= mb_substr($value, $i, 1, $charset) . '%';
                    }
                }
            } else {
                $length = strlen($value);
                for ($i = 0; $i < $length; ++$i) {
                    $pattern .= $value[$i] . '%';
                }
            }

            return $pattern;
        }
    }

}
