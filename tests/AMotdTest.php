<?php

declare(strict_types=1);
namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Motd;

    require_once __DIR__ . '/../config/constants.php';

    if (!function_exists('translate_inline')) {
        function translate_inline($t, $ns = false)
        {
            return $t;
        }
    }
    if (!function_exists('output_notl')) {
        function output_notl(string $f, ...$args)
        {
            global $forms_output;
            $forms_output .= vsprintf($f, $args);
        }
    }
    if (!function_exists('rawoutput')) {
        function rawoutput($t)
        {
            global $forms_output;
            $forms_output .= $t;
        }
    }
    if (!function_exists('output')) {
        function output(string $f, ...$args)
        {
            global $forms_output;
            $forms_output .= vsprintf($f, $args);
        }
    }
    if (!function_exists('addnav')) {
        function addnav(...$args)
        {
        }
    }
    if (!function_exists('httppost')) {
        function httppost($name)
        {
            return $_POST[$name] ?? false;
        }
    }
    if (!function_exists('invalidatedatacache')) {
        function invalidatedatacache(string $name)
        {
        }
    }

    final class AMotdTest extends TestCase
    {
        protected function setUp(): void
        {
            global $forms_output, $session;
            $forms_output = '';
            $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'superuser' => 0]];
            \Lotgd\MySQL\Database::$settings_table = [];
            \Lotgd\MySQL\Database::$onlineCounter = 0;
            \Lotgd\MySQL\Database::$affected_rows = 0;
            \Lotgd\MySQL\Database::$lastSql = '';
            $_POST = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['session'], $GLOBALS['forms_output']);
            $_POST = [];
        }

        public function testPollItemShowsRadioButtonsForLoggedInUser(): void
        {
            global $forms_output;
            $data = ['body' => 'Question?', 'opt' => ['Yes', 'No']];
            $body = serialize($data);

            Motd::pollItem(1, 'Subject', $body, 'Author', '2024-01-01 00:00:00');

            $this->assertStringContainsString("type='radio' name='choice'", $forms_output);
        }

        public function testPollItemUnserializesSlashedData(): void
        {
            global $forms_output;
            $data = ['body' => 'Question?', 'opt' => ['Yes', 'No']];
            $body = addslashes(serialize($data));

            Motd::pollItem(1, 'Subject', $body, 'Author', '2024-01-01 00:00:00');

            $this->assertStringContainsString("type='radio' name='choice'", $forms_output);
        }

        public function testSavePollSerializesData(): void
        {
            $_POST['motdtitle'] = 'Title';
            $_POST['motdbody'] = 'Question?';
            $_POST['opt'] = ['Yes', 'No'];

            Motd::savePoll();

            $expected = addslashes(serialize(['body' => 'Question?', 'opt' => ['Yes', 'No']]));
            $this->assertStringContainsString($expected, \Lotgd\MySQL\Database::$lastSql);
        }
    }
}
