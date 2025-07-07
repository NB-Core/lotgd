<?php
declare(strict_types=1);

namespace Lotgd\MySQL;

use Lotgd\Backtrace;
use Lotgd\DataCache;
use Lotgd\DateTime;
global $dbinfo;

$dbinfo = [];

/**
 * Collection of database helper methods.
 */
class Database
{
    /** @var DbMysqli|null */
    protected static ?DbMysqli $instance = null;
    /** @var array<string,int|string> */
    protected static array $dbinfo = [
        'queriesthishit' => 0,
        'querytime'      => 0,
        'DB_DATACACHEPATH' => '',
    ];

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
     * Set the client character set.
     */
    public static function setCharset(string $charset): bool
    {
        return self::getInstance()->setCharset($charset);
    }

    /**
     * Execute a SQL query.
     *
     * @return array|bool|\mysqli_result
     */
    public static function query(string $sql, bool $die = true): array|bool|\mysqli_result
    {
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return [];
        }
        global $session, $dbinfo;
        if (!isset($dbinfo['queriesthishit'])) {
            $dbinfo['queriesthishit'] = 0;
        }
        $dbinfo['queriesthishit']++;
        $starttime = DateTime::getMicroTime();
        $r = self::getInstance()->query($sql);

        if (!$r && $die === true) {
            if (defined('IS_INSTALLER') && IS_INSTALLER) {
                return [];
            }
            if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEVELOPER)) {
                die("<pre>" . HTMLEntities($sql, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</pre>' . self::error() . Backtrace::show());
            }
            die('A most bogus error has occurred.  I apologise, but the page you were trying to access is broken.  Please use your browser\'s back button and try again.');
        }
        $endtime = DateTime::getMicroTime();
        if ($endtime - $starttime >= 1.00 && isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT)) {
            $s = trim($sql);
            if (strlen($s) > 800) {
                $s = substr($s, 0, 400) . ' ... ' . substr($s, strlen($s) - 400);
            }
            debug('Slow Query (' . round($endtime - $starttime, 2) . 's): ' . HTMLEntities($s, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '`n');
        }
        unset($dbinfo['affected_rows']);
        $dbinfo['affected_rows'] = self::affectedRows();
        if (!isset($dbinfo['querytime'])) {
            $dbinfo['querytime'] = 0;
        }
        $dbinfo['querytime'] += $endtime - $starttime;
        return $r;
    }

    /**
     * Execute a SQL query and cache the result.
     *
     * @return array<mixed>
     */
    public static function queryCached(string $sql, string $name, int $duration = 900): array
    {
        global $dbinfo;
        $data = DataCache::datacache($name, $duration);
        if (is_array($data)) {
            reset($data);
            $dbinfo['affected_rows'] = -1;
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
    public static function fetchAssoc(array|\mysqli_result &$result): array|false|null
    {
        if (is_array($result)) {
            $val = current($result);
            next($result);
            return $val;
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
        return self::getInstance()->insertId();
    }

    /**
     * Count the number of rows in a result set.
     *
     * @param array|\mysqli_result $result
     */
    public static function numRows(array|\mysqli_result $result): int
    {
        if (is_array($result)) {
            return count($result);
        }
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return 0;
        }
        return self::getInstance()->numRows($result);
    }

    /**
     * Get the number of rows affected by the last query.
     */
    public static function affectedRows(): int
    {
        global $dbinfo;
        if (isset($dbinfo['affected_rows'])) {
            return $dbinfo['affected_rows'];
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
        return self::getInstance()->escape($string);
    }

    /**
     * Free a result set.
     *
     * @param array|\mysqli_result $result
     */
    public static function freeResult(array|\mysqli_result $result): bool
    {
        if (is_array($result)) {
            return true;
        }
        if ((defined('DB_NODB') && DB_NODB) && !defined('LINK')) {
            return false;
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
        return self::getInstance()->tableExists($tablename);
    }

    /**
     * Get a table name with the configured prefix.
     */
    public static function prefix(string $tablename, ?string $force = null): string
    {
        global $DB_PREFIX;
        if ($force === null) {
            $special_prefixes = [];
            if (file_exists('prefixes.php')) {
                require_once 'prefixes.php';
            }
            $prefix = $DB_PREFIX;
            if (isset($special_prefixes[$tablename])) {
                $prefix = $special_prefixes[$tablename];
            }
        } else {
            $prefix = $force;
        }
        return $prefix . $tablename;
    }
}

