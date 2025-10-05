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
            unset($GLOBALS['lotgd_mail_player_search']);
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

        protected function tearDown(): void
        {
            unset($GLOBALS['lotgd_mail_player_search']);
        }

        public function testRecipientDropdownShownForPartialNames(): void
        {
            global $forms_output;

            $_POST['to'] = 'ja';

            $conn = Database::getDoctrineConnection();
            Database::$mockResults = [];
            $conn->fetchAllResults = [
                [],
                [],
                [
                    ['acctid' => 10, 'login' => 'john', 'name' => 'John', 'superuser' => 0, 'locked' => 0],
                    ['acctid' => 11, 'login' => 'jane', 'name' => 'Jane', 'superuser' => 0, 'locked' => 0],
                ],
            ];

            \mailWrite();

            $this->assertGreaterThanOrEqual(3, $conn->executeQueryParams);
            $this->assertSame('ja', $conn->executeQueryParams[1]['loginExact']);
            $this->assertSame('%ja%', $conn->executeQueryParams[2]['namePattern']);
            $this->assertSame('%j%a%', $conn->executeQueryParams[2]['nameCharacterPattern']);
            $this->assertSame('ja', $conn->executeQueryParams[2]['nameExact']);
            $this->assertStringContainsString("<select name='to' id='to'", $forms_output);
        }

        public function testFallbackSearchHandlesQuotedNames(): void
        {
            global $forms_output;

            $_POST['to'] = "O'";

            $conn = Database::getDoctrineConnection();
            Database::$mockResults = [];
            $conn->fetchAllResults = [
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

            \mailWrite();

            $this->assertGreaterThanOrEqual(2, $conn->executeQueryParams);
            $this->assertSame("O'", $conn->executeQueryParams[0]['loginExact']);
            $this->assertSame("%O'%", $conn->executeQueryParams[1]['namePattern']);
            $this->assertSame("%O%'%", $conn->executeQueryParams[1]['nameCharacterPattern']);
            $this->assertStringContainsString('Shaun &quot;Quote&quot; O\'Connor', $forms_output);
        }

        public function testFallbackSearchHandlesMultibyteNames(): void
        {
            global $forms_output;

            $_POST['to'] = 'さく';

            $conn = Database::getDoctrineConnection();
            Database::$mockResults = [];
            $conn->fetchAllResults = [
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

            \mailWrite();

            $this->assertGreaterThanOrEqual(2, $conn->executeQueryParams);
            $this->assertSame('さく', $conn->executeQueryParams[0]['loginExact']);
            $this->assertSame('%さく%', $conn->executeQueryParams[1]['namePattern']);
            $this->assertSame('%さ%く%', $conn->executeQueryParams[1]['nameCharacterPattern']);
            $this->assertStringContainsString('さくら&quot;🌸&quot;', $forms_output);
        }
    }
}
