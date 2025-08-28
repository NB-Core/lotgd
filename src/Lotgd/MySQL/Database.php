<?php

declare(strict_types=1);

/**
 * Collection of database helper methods.
 */

namespace Lotgd\MySQL;

use Lotgd\Backtrace;
use Lotgd\DataCache;
use Lotgd\DateTime;
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
     *  - 'queriesthishit'   Number of queries executed for the current request.
     *  - 'querytime'        Cumulative time spent running SQL queries.
     *  - 'DB_DATACACHEPATH' Optional path for datacache files.
     *
     * @var array<string,int|string>
     */
    protected static array $dbinfo = [
        'queriesthishit'   => 0,
        'querytime'        => 0,
        'DB_DATACACHEPATH' => '',
    ];

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
        if (!isset(self::$dbinfo['queriesthishit'])) {
            self::$dbinfo['queriesthishit'] = 0;
        }
        self::$dbinfo['queriesthishit']++;
        $starttime = DateTime::getMicroTime();
        static $configExists = null;
        if ($configExists === null) {
            $rootDir = dirname(__DIR__, 3);
            $configExists = file_exists($rootDir . '/dbconnect.php');
        }

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

        if (!$r && $die === true) {
            if (defined('IS_INSTALLER') && IS_INSTALLER) {
                return [];
            }
            if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEVELOPER)) {
                die("<pre>" . HTMLEntities($sql, ENT_COMPAT, getsetting('charset', 'UTF-8')) . '</pre>' . self::error() . Backtrace::show());
            }
            die('A most bogus error has occurred.  I apologise, but the page you were trying to access is broken.  Please use your browser\'s back button and try again.');
        }
        $endtime = DateTime::getMicroTime();
        if ($endtime - $starttime >= 1.00 && isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT)) {
            $s = trim($sql);
            if (strlen($s) > 800) {
                $s = substr($s, 0, 400) . ' ... ' . substr($s, strlen($s) - 400);
            }
            debug('Slow Query (' . round($endtime - $starttime, 2) . 's): ' . HTMLEntities($s, ENT_COMPAT, getsetting('charset', 'UTF-8')) . '`n');
        }
        unset(self::$dbinfo['affected_rows']);
        self::$dbinfo['affected_rows'] = $affected ?? self::affectedRows();
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
        $data = DataCache::datacache($name, $duration);
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
        DataCache::updatedatacache($name, $data);
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
        return self::getInstance()->getServerVersion();
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
