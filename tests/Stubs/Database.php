<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

/**
 * Fake database used for tests.
 */
class Database
{
    public static array $settings_table = [];
    public static int $onlineCounter = 0;
    public static int $affected_rows = 0;
    public static string $lastSql = '';
    public static ?object $doctrineConnection = null;
    public static ?object $instance = null;

    public static function prefix(string $name, bool $force = false): string
    {
        return $name;
    }

    public static function query(string $sql, bool $die = true): array|bool|null
    {
        global $accounts_table, $mail_table, $last_query_result;
        self::$lastSql = $sql;

        if (class_exists('Lotgd\\Doctrine\\Bootstrap')) {
            $conn = self::getDoctrineConnection();
            $conn->executeQuery($sql);
            $last_query_result = [['ok' => true]];
            return $last_query_result;
        }

        $mysqli = self::getInstance();
        if ($mysqli) {
            $last_query_result = $mysqli->query($sql);
            return $last_query_result;
        }

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

        if (preg_match("/SELECT count\\(messageid\\) AS count FROM mail WHERE msgto=(\d+)(.*)/", $sql, $m)) {
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

    /**
     * Fetch a single row as an associative array.
     *
     * When an array is supplied the argument is passed by reference and the
     * first element is removed. This simulates the internal pointer behaviour
     * of {@link \mysqli_result::fetch_assoc()} so that repeated calls continue
     * to return subsequent rows.
     */
    public static function fetchAssoc(array|\mysqli_result &$result): mixed
    {
        if (is_array($result)) {
            return array_shift($result);
        }

        if ($result instanceof \mysqli_result) {
            return $result->fetch_assoc();
        }

        return null;
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

    public static function getDoctrineConnection()
    {
        if (!self::$doctrineConnection) {
            self::$doctrineConnection = new class {
                public array $queries = [];
                public function executeQuery(string $sql)
                {
                    $this->queries[] = $sql;
                    return true;
                }
            };
        }

        return self::$doctrineConnection;
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new DbMysqli();
        }

        return self::$instance;
    }
}

class_alias(Database::class, 'Lotgd\\MySQL\\Database');
