<?php

declare(strict_types=1);

namespace Lotgd\MySQL {
    if (!class_exists('Lotgd\\MySQL\\Database', false)) {
        class Database
        {
            public static array $settings_table = [];
            public static int $onlineCounter = 0;
            public static int $affected_rows = 0;
            public static string $lastSql = '';

            public static function prefix(string $name, bool $force = false): string
            {
                return $name;
            }

            public static function query(string $sql, bool $die = true)
            {
                global $accounts_table, $mail_table, $last_query_result;
                self::$lastSql = $sql;

                if (preg_match("/SELECT prefs,emailaddress FROM accounts WHERE acctid='?(\d+)'?;/", $sql, $m)) {
                    $acctid = (int) $m[1];
                    $row = $accounts_table[$acctid] ?? ['prefs' => '', 'emailaddress' => ''];
                    $last_query_result = [$row];
                    return $last_query_result;
                }

                if (strpos($sql, 'INSERT INTO mail') === 0) {
                    if (preg_match("/\((?:'|\")?(\d+)(?:'|\")?,(?:'|\")?(\d+)(?:'|\")?,(?:'|\")?(.*?)(?:'|\")?,(?:'|\")?(.*?)(?:'|\")?,(?:'|\")?(.*?)(?:'|\")?\)/", $sql, $m)) {
                        $from = (int) $m[1];
                        $to = (int) $m[2];
                        $subject = $m[3];
                        $body = $m[4];
                        $sent = $m[5];
                    } else {
                        $from = $to = 0;
                        $subject = '';
                        $body = '';
                        $sent = '';
                    }
                    $id = count($mail_table) + 1;
                    $mail_table[] = ['messageid' => $id, 'msgfrom' => $from, 'msgto' => $to, 'subject' => $subject, 'body' => $body, 'sent' => $sent, 'seen' => 0];
                    $last_query_result = true;
                    return true;
                }

                if (preg_match("/SELECT name FROM accounts WHERE acctid='?(\d+)'?;/", $sql, $m)) {
                    $acctid = (int) $m[1];
                    $row = ['name' => $accounts_table[$acctid]['name'] ?? ''];
                    $last_query_result = [$row];
                    return $last_query_result;
                }

                if (preg_match("/SELECT count\(messageid\) AS count FROM mail WHERE msgto=(\d+)(.*)/", $sql, $m)) {
                    $userId = (int) $m[1];
                    $onlyUnread = strpos($sql, 'seen=0') !== false;
                    $count = 0;
                    foreach ($mail_table as $row) {
                        if ($row['msgto'] == $userId && (!$onlyUnread || $row['seen'] == 0)) {
                            $count++;
                        }
                    }
                    $last_query_result = [['count' => $count]];
                    return $last_query_result;
                }

                if (strpos($sql, 'SELECT count(acctid) as counter FROM accounts') === 0) {
                    $last_query_result = [['counter' => self::$onlineCounter]];
                    return $last_query_result;
                }

                if (preg_match('/SELECT \* FROM (.+)/', $sql, $m)) {
                    if ($m[1] === 'settings') {
                        $last_query_result = [];
                        foreach (self::$settings_table as $k => $v) {
                            $last_query_result[] = ['setting' => $k, 'value' => $v];
                        }
                        return $last_query_result;
                    }
                }

                if (strpos($sql, 'INSERT INTO settings') === 0) {
                    if (preg_match('/VALUES\((.+),(.+)\)/', $sql, $m)) {
                        $name = trim($m[1], "'\"");
                        $value = trim($m[2], "'\"");
                    } else {
                        $name = $value = '';
                    }
                    self::$settings_table[$name] = $value;
                    self::$affected_rows = 1;
                    $last_query_result = true;
                    return true;
                }

                if (preg_match('/UPDATE (.+) SET value=(.+) WHERE setting=(.+)/', $sql, $m)) {
                    if ($m[1] === 'settings') {
                        $value = trim($m[2], "'\"");
                        $name = trim($m[3], "'\"");
                        if (isset(self::$settings_table[$name])) {
                            self::$settings_table[$name] = $value;
                            self::$affected_rows = 1;
                        } else {
                            self::$affected_rows = 0;
                        }
                        $last_query_result = true;
                        return true;
                    }
                }

                $last_query_result = [];
                return [];
            }

            public static function fetchAssoc(array|\mysqli_result &$result)
            {
                return array_shift($result);
            }

            public static function freeResult(array|\mysqli_result &$result): bool
            {
                $result = null;
                return true;
            }

            public static function numRows(array|\mysqli_result $result): int
            {
                return is_array($result) ? count($result) : 0;
            }

            public static function affectedRows(): int
            {
                return self::$affected_rows;
            }

            public static function queryCached(string $sql, string $name, int $duration = 900): array
            {
                return [];
            }
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Motd;

    require_once __DIR__ . '/../config/constants.php';

    if (!function_exists('translate_inline')) {
        function translate_inline($t, $ns = false) { return $t; }
    }
    if (!function_exists('output_notl')) {
        function output_notl(string $f, ...$args) { global $forms_output; $forms_output .= vsprintf($f, $args); }
    }
    if (!function_exists('rawoutput')) {
        function rawoutput($t) { global $forms_output; $forms_output .= $t; }
    }
    if (!function_exists('output')) {
        function output(string $f, ...$args) { global $forms_output; $forms_output .= vsprintf($f, $args); }
    }
    if (!function_exists('addnav')) {
        function addnav(...$args) {}
    }
    if (!function_exists('httppost')) {
        function httppost($name) { return $_POST[$name] ?? false; }
    }
    if (!function_exists('invalidatedatacache')) {
        function invalidatedatacache(string $name) {}
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
