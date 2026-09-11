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
    use Lotgd\Settings;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DummySettings;
    use PHPUnit\Framework\TestCase;

    /**
     * Runs in its own process: this class define()s process-global constants,
     * and a constant cannot be undefined. Without isolation the first test to
     * run here decides them for every test that follows, which is one of the
     * two reasons the suite used to pass only in alphabetical order.
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
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
            $this->installSettingsStub();
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
            Output::setInstance(null);
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['lotgd_mail_player_search']);
            $this->removeSettingsStub();
        }

        private function installSettingsStub(): void
        {
            $settings = new DummySettings([
                'charset'             => 'UTF-8',
                'mailsizelimit'       => 1024,
                'superuseryommessage' => "Asking an admin for gems, gold, weapons, armor, or anything else which you have not earned will not be honored. If you are experiencing problems with the game, please use the 'Petition for Help' link instead of contacting an admin directly.",
            ]);

            Settings::setInstance($settings);
            $GLOBALS['settings'] = $settings;
        }

        private function removeSettingsStub(): void
        {
            Settings::setInstance(null);
            unset($GLOBALS['settings']);
        }

        public function testRecipientDropdownShownForPartialNames(): void
        {
            global $forms_output;

            $_POST['to'] = 'ja';

            Database::$mockResults = [
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
