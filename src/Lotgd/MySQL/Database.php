<?php

declare(strict_types=1);

/**
 * Collection of database helper methods.
 */

namespace Lotgd\MySQL;

use Lotgd\Settings;
use Lotgd\Backtrace;
use Lotgd\DataCache;
use Lotgd\DateTime;
use Lotgd\Output;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result as DoctrineResult;
use Lotgd\Doctrine\Bootstrap;

class Database
{
    /** @var DbMysqli|null */
    protected static ?DbMysqli $instance = null;
    /** @var Connection|null */
    protected static ?Connection $doctrine = null;
    /**
     * Runtime statistics collected during query execution.
     *
     *  - 'querytime'        Cumulative time spent running SQL queries.
     *  - 'DB_DATACACHEPATH' Optional path for datacache files.
     *
     * @var array<string,int|string>
     */
    protected static array $dbinfo = [
        'querytime'        => 0,
        'DB_DATACACHEPATH' => '',
    ];

    /**
     * Last cache key used by {@see queryCached} or other database helpers.
     * Primarily exposed for testing translation caching behavior.
     */
    public static string $lastCacheName = '';

    /**
     * Number of queries executed for the current request.
     */
    private static int $queryCount = 0;

    /**
     * Default table prefix for prefixed database tables.
     */
    private static string $prefix = '';

    /**
     * Set the default table prefix.
     */
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * Get a statistic from the database info store.
     */
    public static function getInfo(string $key, mixed $default = null): mixed
    {
        return self::$dbinfo[$key] ?? $default;
    }

    /**
     * Get the number of queries executed during the current request.
     */
    public static function getQueryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * Get the singleton database connection wrapper.
     */
    public static function getInstance(): DbMysqli
    {
        if (!self::$instance) {
            self::$instance = new DbMysqli();
        }
        return self::$instance;
    }

    /**
     * Get or create the Doctrine DBAL connection.
     */
    public static function getDoctrineConnection(): Connection
    {
        if (!self::$doctrine) {
            $em = Bootstrap::getEntityManager();
            self::$doctrine = $em->getConnection();
        }

        return self::$doctrine;
    }

    /**
     * Assign the Doctrine DBAL connection.
     */
    public static function setDoctrineConnection(Connection $conn): void
    {
        self::$doctrine = $conn;
    }

    /**
     * Set the client character set.
     */
    public static function setCharset(string $charset): bool
    {
        return self::getInstance()->setCharset($charset);
    }

    /**
     * Execute a SQL query.
     *
     * @return array|bool|\mysqli_result|DoctrineResult
     */
    public static function query(string $sql, bool $die = true): array|bool|\mysqli_result|DoctrineResult
    {
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return [];
        }
        global $session;
        self::$queryCount++;
        $starttime = DateTime::getMicroTime();
        static $configExists = null;
        if ($configExists === null) {
            $rootDir = dirname(__DIR__, 3);
            $configExists = file_exists($rootDir . '/dbconnect.php');
        }

        $affected = 0;
        if (self::$doctrine || $configExists) {
            $conn = self::$doctrine ?? self::getDoctrineConnection();
            $trim = ltrim($sql);
            while ($trim !== '' && $trim[0] === '(') {
                $trim = ltrim(substr($trim, 1));
            }
            $keyword = strtolower(strtok($trim, " \t\n\r"));
            $readOps = ['select', 'show', 'describe', 'desc', 'explain', 'pragma', 'optimize', 'analyze'];
            if (in_array($keyword, $readOps, true)) {
                $r = $conn->executeQuery($sql);
                $affected = $r->rowCount();
            } else {
                $affected = $conn->executeStatement($sql);
                $r = $affected >= 0;
            }
        } else {
            $r = self::getInstance()->query($sql);
            $affected = self::getInstance()->affectedRows();
        }

        $charset = 'UTF-8';

        if (!$r && $die === true) {
            if (defined('IS_INSTALLER') && IS_INSTALLER) {
                return [];
            }
            $charset = Settings::getInstance()->getSetting('charset', 'UTF-8');
            if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEVELOPER)) {
                die("<pre>" . HTMLEntities($sql, ENT_COMPAT, $charset) . '</pre>' . self::error() . Backtrace::show());
            }
            die('A most bogus error has occurred.  I apologise, but the page you were trying to access is broken.  Please use your browser\'s back button and try again.');
        }
        $endtime = DateTime::getMicroTime();
        if ($endtime - $starttime >= 1.00 && isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT)) {
            $s = trim($sql);
            if (strlen($s) > 800) {
                $s = substr($s, 0, 400) . ' ... ' . substr($s, strlen($s) - 400);
            }
            $charset = Settings::getInstance()->getSetting('charset', 'UTF-8');
            Output::getInstance()->debug('Slow Query (' . round($endtime - $starttime, 2) . 's): ' . HTMLEntities($s, ENT_COMPAT, $charset) . '`n');
        }
        unset(self::$dbinfo['affected_rows']);
        self::$dbinfo['affected_rows'] = $affected;
        if (!isset(self::$dbinfo['querytime'])) {
            self::$dbinfo['querytime'] = 0;
        }
        self::$dbinfo['querytime'] += $endtime - $starttime;
        return $r;
    }

    /**
     * Execute a SQL query and cache the result.
     *
     * @return array<mixed>
     */
    public static function queryCached(string $sql, string $name, int $duration = 900): array
    {
        $data = DataCache::getInstance()->datacache($name, $duration);
        if (is_array($data)) {
            reset($data);
            self::$dbinfo['affected_rows'] = -1;
            return $data;
        }
        $result = self::query($sql);
        $data = [];
        while ($row = self::fetchAssoc($result)) {
            $data[] = $row;
        }
        DataCache::getInstance()->updatedatacache($name, $data);
        reset($data);
        return $data;
    }

    /**
     * Retrieve the last connection error.
     */
    public static function error(): string
    {
        $r = self::getInstance()->error();
        if ($r == '' && (defined('DB_NODB') && DB_NODB) && !defined('DB_INSTALLER_STAGE4')) {
            return 'The database connection was never established';
        }
        return $r;
    }

    /**
     * Fetch an associative row from a result set or array.
     *
     * @param array|\mysqli_result $result
     *
     * @return array|false|null
     */
    public static function fetchAssoc(array|\mysqli_result|DoctrineResult &$result): array|false|null
    {
        if (is_array($result)) {
            if (!array_is_list($result)) {
                $result = [$result];
            }
            $val = current($result);
            next($result);
            return $val;
        }
        if ($result instanceof DoctrineResult) {
            return $result->fetchAssociative();
        }

        return self::getInstance()->fetchAssoc($result);
    }

    /**
     * Get the last inserted ID.
     */
    public static function insertId(): int|string
    {
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return -1;
        }
        if (self::$doctrine) {
            return self::$doctrine->lastInsertId();
        }

        return self::getInstance()->insertId();
    }

    /**
     * Count the number of rows in a result set.
     *
     * @param array|\mysqli_result|\Doctrine\DBAL\Result $result
     */
    public static function numRows(array|\mysqli_result|\Doctrine\DBAL\Result $result): int
    {
        if (is_array($result)) {
            return count($result);
        }
        if ($result instanceof \Doctrine\DBAL\Result) {
            return $result->rowCount();
        }
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return 0;
        }
        if ($result instanceof DoctrineResult) {
            return $result->rowCount();
        }

        return self::getInstance()->numRows($result);
    }

    /**
     * Get the number of rows affected by the last query.
     */
    public static function affectedRows(): int
    {
        if (isset(self::$dbinfo['affected_rows'])) {
            return self::$dbinfo['affected_rows'];
        }
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return 0;
        }
        return self::getInstance()->affectedRows();
    }

    /**
     * Open a persistent connection to the database server.
     */
    public static function pconnect(string $host, string $user, string $pass): bool
    {
        return self::getInstance()->pconnect($host, $user, $pass);
    }

    /**
     * Open a connection to the database server.
     */
    public static function connect(string $host, string $user, string $pass): bool
    {
        return self::getInstance()->connect($host, $user, $pass);
    }

    /**
     * Retrieve the version of the database server.
     */
    public static function getServerVersion(): string
    {
        if (self::$doctrine) {
            $conn = self::$doctrine;

            // Doctrine DBAL 3+ exposes "getNativeConnection" while older versions use
            // "getWrappedConnection". Try both to access the underlying driver.
            if (method_exists($conn, 'getNativeConnection')) {
                $driverConn = $conn->getNativeConnection();
            } elseif (method_exists($conn, 'getWrappedConnection')) {
                $driverConn = $conn->getWrappedConnection();
            } else {
                $driverConn = null;
            }

            if ($driverConn && method_exists($driverConn, 'getServerVersion')) {
                return $driverConn->getServerVersion();
            }

            return (string) $conn->fetchOne('SELECT VERSION()');
        }

        if (self::$instance) {
            return self::$instance->getServerVersion();
        }

        throw new \RuntimeException('No database connection available to determine server version.');
    }

    /**
     * Select a database.
     */
    public static function selectDb(string $dbname): bool
    {
        return self::getInstance()->selectDb($dbname);
    }

    /**
     * Escape a string for use in a query.
     */
    public static function escape(string $string): string
    {
        if (self::$doctrine) {
            $quoted = self::$doctrine->quote($string);
            $unquoted = preg_replace('/^([\'"`])(.*)\1$/', '$2', $quoted);
            return $unquoted !== null ? $unquoted : $quoted;
        }

        return self::getInstance()->escape($string);
    }

    /**
     * Free a result set.
     *
     * @param array|\mysqli_result $result
     */
    public static function freeResult(array|\mysqli_result|DoctrineResult $result): bool
    {
        if (is_array($result)) {
            return true;
        }
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return false;
        }
        if ($result instanceof DoctrineResult) {
            $result->free();
            return true;
        }

        self::getInstance()->freeResult($result);
        return true;
    }

    /**
     * Check whether a table exists.
     */
    public static function tableExists(string $tablename): bool
    {
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return false;
        }
        if (self::$doctrine) {
            $sm = self::getDoctrineConnection()->createSchemaManager();
            return $sm->tablesExist([$tablename]);
        }

        return self::getInstance()->tableExists($tablename);
    }

    /**
     * Get a table name with the configured prefix.
     */
    public static function prefix(string $tablename, string|false|null $force = null): string
    {
        if ($force === null || $force === false) {
            $special_prefixes = [];
            if (file_exists('prefixes.php')) {
                require_once 'prefixes.php';
            }
            $prefix = self::$prefix;
            if (isset($special_prefixes[$tablename])) {
                $prefix = $special_prefixes[$tablename];
            }
        } else {
            $prefix = $force;
        }
        $table = $prefix . $tablename;
        return $table;
    }
}
