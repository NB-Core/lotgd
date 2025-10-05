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

    use Lotgd\Output;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

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
            unset($GLOBALS['lotgd_mail_player_search']);
            $this->resetOutputSingleton();
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

        private function resetOutputSingleton(): void
        {
            $reflection = new ReflectionClass(Output::class);
            $instanceProp = $reflection->getProperty('instance');
            $instanceProp->setAccessible(true);
            $instanceProp->setValue(null, null);
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['lotgd_mail_player_search']);
        }

        public function testRecipientDropdownShownForPartialNames(): void
        {
            global $forms_output;

            $_POST['to'] = 'ja';

            Database::$mockResults = [
                [],
                [],
                [
                    ['acctid' => 10, 'login' => 'john', 'name' => 'John', 'superuser' => 0, 'locked' => 0],
                    ['acctid' => 11, 'login' => 'jane', 'name' => 'Jane', 'superuser' => 0, 'locked' => 0],
                    ['acctid' => 12, 'login' => 'jack', 'name' => 'Jack', 'superuser' => 0, 'locked' => 1],
                ],
            ];

            $conn = Database::getDoctrineConnection();

            \mailWrite();

            $this->assertGreaterThanOrEqual(3, $conn->executeQueryParams);
            $this->assertQueryParamEquals($conn->executeQueryParams, 'loginExact', 'ja');
            $this->assertQueryParamEquals($conn->executeQueryParams, 'namePattern', '%ja%');
            $this->assertQueryParamEquals($conn->executeQueryParams, 'nameCharacterPattern', '%j%a%');
            $this->assertQueryParamEquals($conn->executeQueryParams, 'nameExact', 'ja');
            $this->assertStringContainsString("<select name='to' id='to'", $forms_output);
            $this->assertStringNotContainsString('jack', $forms_output, 'Locked accounts should not appear in options');
        }

        public function testFallbackSearchHandlesQuotedNames(): void
        {
            global $forms_output;

            $_POST['to'] = "O'";

            Database::$mockResults = [
                [],
                [
                    [
                        'acctid'    => 20,
                        'login'     => 'oconnor',
                        'name'      => "Shaun \"Quote\" O'Connor",
                        'superuser' => 0,
                        'locked'    => 0,
                    ],
                ],
            ];

            $conn = Database::getDoctrineConnection();

            \mailWrite();

            $this->assertQueryParamEquals($conn->executeQueryParams, 'loginExact', "O'");
            $this->assertQueryParamEquals($conn->executeQueryParams, 'namePattern', "%O'%");
            $this->assertQueryParamEquals($conn->executeQueryParams, 'nameCharacterPattern', "%O%'%");
            $this->assertQueryParamEquals($conn->executeQueryParams, 'nameExact', "O'");
            $this->assertStringContainsString('Shaun &quot;Quote&quot; O\'Connor', $forms_output);
        }

        public function testFallbackSearchHandlesMultibyteNames(): void
        {
            global $forms_output;

            $_POST['to'] = 'さく';

            Database::$mockResults = [
                [],
                [
                    [
                        'acctid'    => 30,
                        'login'     => 'sakura',
                        'name'      => 'さくら"🌸"',
                        'superuser' => 0,
                        'locked'    => 0,
                    ],
                ],
            ];

            $conn = Database::getDoctrineConnection();

            \mailWrite();

            $this->assertQueryParamEquals($conn->executeQueryParams, 'loginExact', 'さく');
            $this->assertQueryParamEquals($conn->executeQueryParams, 'namePattern', '%さく%');
            $this->assertQueryParamEquals($conn->executeQueryParams, 'nameCharacterPattern', '%さ%く%');
            $this->assertQueryParamEquals($conn->executeQueryParams, 'nameExact', 'さく');
            $this->assertStringContainsString('さくら&quot;🌸&quot;', $forms_output);
        }

        /**
         * @param array<int, array<string, mixed>> $queries
         */
        private function assertQueryParamEquals(array $queries, string $key, string $expected): void
        {
            foreach ($queries as $params) {
                if (array_key_exists($key, $params)) {
                    $this->assertSame($expected, $params[$key]);

                    return;
                }
            }

            $this->fail(sprintf('Failed asserting that query parameters contain key "%s".', $key));
        }
    }
}
