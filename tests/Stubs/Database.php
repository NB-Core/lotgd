<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

/**
 * Fake database used for tests.
 */

if (!class_exists(__NAMESPACE__ . '\\Database', false)) {
    class Database
    {
        public static array $settings_table = [];
        public static array $settings_extended_table = [];
        public static int $onlineCounter = 0;
        public static int $affected_rows = 0;
        public static string $lastSql = '';
        public static string $lastCacheName = '';
        public static array $describe_rows = [];
        public static array $keys_rows = [];
        public static array $full_columns_rows = [];
        public static array $table_status_rows = [];
        public static array $collation_rows = [];
        public static bool $tableExists = true;
        public static ?object $doctrineConnection = null;
        public static ?object $instance = null;
        public static array $queryCacheResults = [];
        public static string $tablePrefix = '';
        /**
         * Queue of mock results returned by {@see query} for unit tests.
         * Each call to {@see query} will shift the next entry.
         *
         * @var array<int, array|object|bool|string|null>
         */
        public static array $mockResults = [];
        public static string $last_error = '';
        public static bool $alterFail = false;
        /**
         * Log of executed SQL queries.
         *
         * @var array<int,string>
         */
        public static array $queries = [];

        public static function connect(string $host, string $user, string $pass): bool
        {
            return self::getInstance()->connect($host, $user, $pass);
        }

        public static function selectDb(string $dbname): bool
        {
            return self::getInstance()->selectDb($dbname);
        }

        public static function prefix(string $name, string|false|null $force = null): string
        {
            if ($force !== null && $force !== false) {
                return $force . $name;
            }

            return self::$tablePrefix . $name;
        }

        public static function setPrefix(string $prefix): void
        {
            self::$tablePrefix = $prefix;
        }

        public static function error(): string
        {
            return self::$instance && method_exists(self::$instance, 'error') ? self::$instance->error() : self::$last_error;
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
        public static function query(string $sql, bool $die = true): array|bool|object|string|null
        {
            global $accounts_table, $mail_table, $last_query_result;
            self::$lastSql = $sql;
            self::$queries[] = $sql;

            if (self::$mockResults) {
                $last_query_result = array_shift(self::$mockResults);
                return $last_query_result;
            }

            if (
                class_exists('Lotgd\\Doctrine\\Bootstrap', false) &&
                (self::$doctrineConnection || \Lotgd\Doctrine\Bootstrap::$conn) &&
                (
                    !self::$instance ||
                    get_class(self::$instance) === \Lotgd\MySQL\DbMysqli::class
                )
            ) {
                $conn = self::getDoctrineConnection();
                $trim = ltrim($sql);
                while ($trim !== '' && $trim[0] === '(') {
                    $trim = ltrim(substr($trim, 1));
                }
                $keyword = strtolower(strtok($trim, " \t\n\r"));
                $readOps = ['select', 'show', 'describe', 'desc', 'explain', 'pragma', 'optimize', 'analyze'];
                if (in_array($keyword, $readOps, true)) {
                    $last_query_result = $conn->executeQuery($sql);
                    self::$affected_rows = $last_query_result->rowCount();
                    return $last_query_result;
                }

                self::$affected_rows = $conn->executeStatement($sql);
                $last_query_result = true;
                return true;
            }

            if (strpos($sql, 'SHOW FULL COLUMNS FROM') === 0) {
                $last_query_result = self::$full_columns_rows;
                return $last_query_result;
            }

            if (strpos($sql, 'DESCRIBE ') === 0) {
                $last_query_result = self::$describe_rows;
                return $last_query_result;
            }

            if (preg_match("/SHOW TABLE STATUS WHERE Name = '([^']+)'/i", $sql, $m)) {
                $name = stripslashes($m[1]);
                $rows = self::$table_status_rows;
                if ($rows && array_key_exists('Name', $rows[0])) {
                    $rows = array_values(
                        array_filter(
                            $rows,
                            fn ($row) => ($row['Name'] ?? '') === $name
                        )
                    );
                }
                $last_query_result = $rows;
                return $last_query_result;
            }

            if (preg_match("/SHOW TABLE STATUS LIKE '([^']+)'/i", $sql, $m)) {
                $pattern = stripslashes($m[1]);
                $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
                $rows = self::$table_status_rows;
                if ($rows && array_key_exists('Name', $rows[0])) {
                    $rows = array_values(
                        array_filter(
                            $rows,
                            fn ($row) => preg_match($regex, $row['Name'] ?? '')
                        )
                    );
                }
                $last_query_result = $rows;
                return $last_query_result;
            }

            if (strpos($sql, 'SHOW KEYS FROM') === 0) {
                $last_query_result = self::$keys_rows;
                return $last_query_result;
            }

            if (strpos($sql, 'SHOW COLLATION') === 0) {
                if (self::$collation_rows) {
                    $last_query_result = array_shift(self::$collation_rows);
                } else {
                    $last_query_result = [];
                }
                return $last_query_result;
            }

            if (strpos($sql, 'SHOW CHARACTER SET') === 0) {
                $charset = 'utf8mb4';
                if (preg_match("/WHERE Charset = '([^']+)'/i", $sql, $m)) {
                    $charset = stripslashes($m[1]);
                } elseif (preg_match("/LIKE '([^']+)'/i", $sql, $m)) {
                    $charset = stripslashes($m[1]);
                }
                $maxlen = match ($charset) {
                    'utf8mb4' => 4,
                    'utf8'    => 3,
                    'latin1'  => 1,
                    default   => 1,
                };
                $last_query_result = [[
                    'Charset' => $charset,
                    'Maxlen'  => $maxlen,
                ]];
                return $last_query_result;
            }

            if (strpos($sql, 'SHOW DATABASES LIKE') === 0) {
                $last_query_result = [['Database' => 'lotgd']];
                return $last_query_result;
            }

            $mysqli = self::getInstance();

            if (strpos($sql, 'ALTER TABLE') === 0) {
                if (self::$alterFail) {
                    self::$last_error = 'Alter table failed';
                    $last_query_result = false;
                    return false;
                }

                $last_query_result = true;
                return true;
            }

            if (preg_match("/SELECT prefs,emailaddress FROM accounts WHERE acctid='?(\d+)'?/", $sql, $m)) {
                $acctid = (int) $m[1];
                $row = $accounts_table[$acctid] ?? ['prefs' => '', 'emailaddress' => ''];
                $last_query_result = [$row];
                return $last_query_result;
            }

            if (preg_match("/SELECT prefs FROM accounts WHERE acctid='?(\d+)'?/", $sql, $m)) {
                $acctid = (int) $m[1];
                $prefs = $accounts_table[$acctid]['prefs'] ?? '';
                $last_query_result = [['prefs' => $prefs]];
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

            if (preg_match("/SELECT deathmessage,taunt FROM deathmessages/", $sql)) {
                $last_query_result = [[
                'deathmessage' => '{goodguyname} met {badguyname} {where}.',
                'taunt'        => 1,
                ]];
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
                $table = $m[1];
                if ($table === 'settings' || $table === 'settings_extended') {
                    $rows = $table === 'settings'
                        ? self::$settings_table
                        : self::$settings_extended_table;
                    $last_query_result = [];
                    foreach ($rows as $k => $v) {
                        $last_query_result[] = ['setting' => $k, 'value' => $v];
                    }
                    return $last_query_result;
                }
            }

            if (preg_match('/INSERT INTO (settings(?:_extended)?) /i', $sql, $tableMatch)) {
                $table = $tableMatch[1];
                if (preg_match('/VALUES\s*\(([^,]+),([^\)]+)\)/i', $sql, $m)) {
                    $name  = trim($m[1], "'\" ");
                    $value = trim($m[2], "'\" ");
                } else {
                    $name = $value = '';
                }

                if ($table === 'settings') {
                    $target =& self::$settings_table;
                } else {
                    $target =& self::$settings_extended_table;
                }

                $exists   = array_key_exists($name, $target);
                $oldValue = $target[$name] ?? null;
                $target[$name] = $value;

                if (!$exists) {
                    self::$affected_rows = 1;
                } elseif ($oldValue !== $value) {
                    self::$affected_rows = 2;
                } else {
                    self::$affected_rows = 0;
                }

                $last_query_result = true;
                return true;
            }

            if (preg_match('/UPDATE (.+) SET value=(.+) WHERE setting=(.+)/', $sql, $m)) {
                $table = $m[1];
                if ($table === 'settings' || $table === 'settings_extended') {
                    $value = trim($m[2], "'\"");
                    $name = trim($m[3], "'\"");
                    if ($table === 'settings') {
                        $target =& self::$settings_table;
                    } else {
                        $target =& self::$settings_extended_table;
                    }

                    if (isset($target[$name])) {
                        $target[$name] = $value;
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
        public static function fetchAssoc(mixed &$result): mixed
        {
            if (is_array($result)) {
                if (!array_is_list($result)) {
                    $result = [$result];
                }

                return array_shift($result);
            }

            if (is_object($result) && method_exists($result, 'fetchAssociative')) {
                return $result->fetchAssociative();
            }

            if ($result instanceof \mysqli_result) {
                return $result->fetch_assoc();
            }

            return null;
        }

        public static function freeResult(array|object &$result): bool
        {
            if (is_object($result) && method_exists($result, 'free')) {
                $result->free();
            }
            $result = null;
            return true;
        }

        public static function numRows(mixed $result): int
        {
            if (is_object($result) && method_exists($result, 'rowCount')) {
                return $result->rowCount();
            }

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
            return self::$tableExists;
        }

        public static function queryCached(string $sql, string $name, int $duration = 900): array
        {
            self::$lastSql = $sql;
            self::$lastCacheName = $name;
            return self::$queryCacheResults[$name] ?? [];
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
}
