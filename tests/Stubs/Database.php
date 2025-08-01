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
    public static array $describe_rows = [];
    public static array $keys_rows = [];
    public static ?object $doctrineConnection = null;
    public static ?object $instance = null;

    public static function prefix(string $name, bool $force = false): string
    {
        return $name;
    }

    /**
     * Executes a database query and returns the result.
     *
     * @param string $sql The SQL query to execute.
     * @param bool   $die Whether to terminate execution on error (default: true).
     *
     * @return array|null Returns an array of results for SELECT queries.
     *                    Returns null if no results are found or for non-SELECT queries.
     *                    Returns a boolean (true/false) for certain operations (e.g., success/failure).
     *                    Returns a string in specific cases (e.g., error messages or debug information).
     */
    public static function query(string $sql, bool $die = true): array|bool|string|null
    {
        global $accounts_table, $mail_table, $last_query_result;
        self::$lastSql = $sql;

        if (class_exists('Lotgd\\Doctrine\\Bootstrap', false) && (self::$doctrineConnection || \Lotgd\Doctrine\Bootstrap::$conn)) {
            $conn = self::getDoctrineConnection();
            $conn->executeQuery($sql);
            self::$affected_rows = 1;
            $last_query_result = [['ok' => true]];
            return $last_query_result;
        }

        if (strpos($sql, 'DESCRIBE ') === 0) {
            $last_query_result = self::$describe_rows;
            return $last_query_result;
        }

        if (strpos($sql, 'SHOW KEYS FROM') === 0) {
            $last_query_result = self::$keys_rows;
            return $last_query_result;
        }

        $mysqli = self::getInstance();

        if (preg_match("/SELECT prefs,emailaddress FROM accounts WHERE acctid='?(\d+)'?/", $sql, $m)) {
            $acctid = (int) $m[1];
            $row = $accounts_table[$acctid] ?? ['prefs' => '', 'emailaddress' => ''];
            $last_query_result = [$row];
            return $last_query_result;
        }

        if (strpos($sql, 'SELECT * FROM modules') === 0) {
            $last_query_result = [];
            return $last_query_result;
        }

        if (preg_match("/SELECT count\\(resultid\\) AS c, MAX\\(choice\\) AS choice FROM pollresults/", $sql)) {
            $last_query_result = [['c' => 0, 'choice' => null]];
            return $last_query_result;
        }

        if (preg_match("/SELECT count\\(resultid\\) AS c, choice FROM pollresults/", $sql)) {
            $last_query_result = [];
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

        if (preg_match("/SELECT name FROM accounts WHERE acctid='?(\d+)'?/", $sql, $m)) {
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

        if ($mysqli) {
            $last_query_result = $mysqli->query($sql);
            return $last_query_result;
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

    public static function insertId(): string
    {
        return '1';
    }

    public static function escape(string $string): string
    {
        return addslashes($string);
    }

    public static function tableExists(string $table): bool
    {
        return true;
    }

    public static function queryCached(string $sql, string $name, int $duration = 900): array
    {
        return [];
    }

    public static function getDoctrineConnection()
    {
        if (!self::$doctrineConnection) {
            if (class_exists('Lotgd\\Doctrine\\Bootstrap', false) && property_exists('Lotgd\\Doctrine\\Bootstrap', 'conn') && \Lotgd\Doctrine\Bootstrap::$conn) {
                self::$doctrineConnection = \Lotgd\Doctrine\Bootstrap::$conn;
            } else {
                self::$doctrineConnection = new DoctrineConnection();
                if (class_exists('Lotgd\\Doctrine\\Bootstrap', false) && property_exists('Lotgd\\Doctrine\\Bootstrap', 'conn')) {
                    \Lotgd\Doctrine\Bootstrap::$conn = self::$doctrineConnection;
                }
            }
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
