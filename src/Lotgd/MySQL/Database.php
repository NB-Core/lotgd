<?php
namespace Lotgd\MySQL;

use Lotgd\DataCache;

global $dbinfo;

$dbinfo = [];

/**
 * Collection of database helper methods.
 */
class Database
{
    protected static $instance;
    protected static $dbinfo = [
        'queriesthishit' => 0,
        'querytime'      => 0,
        'DB_DATACACHEPATH' => '',
    ];

    public static function getInstance(): DbMysqli
    {
        if (!self::$instance) {
            self::$instance = new DbMysqli();
        }
        return self::$instance;
    }

    public static function setCharset($charset)
    {
        return self::getInstance()->setCharset($charset);
    }

    public static function query($sql, $die = true)
    {
        if (defined('DB_NODB') && !defined('LINK')) {
            return [];
        }
        global $session, $dbinfo;
        if (!isset($dbinfo['queriesthishit'])) {
            $dbinfo['queriesthishit'] = 0;
        }
        $dbinfo['queriesthishit']++;
        $starttime = getmicrotime();
        $r = self::getInstance()->query($sql);

        if (!$r && $die === true) {
            if (defined('IS_INSTALLER') && IS_INSTALLER) {
                return [];
            }
            if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEVELOPER)) {
                require_once 'lib/show_backtrace.php';
                die("<pre>" . HTMLEntities($sql, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</pre>' . self::error() . show_backtrace());
            }
            die('A most bogus error has occurred.  I apologise, but the page you were trying to access is broken.  Please use your browser\'s back button and try again.');
        }
        $endtime = getmicrotime();
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

    public static function queryCached($sql, $name, $duration = 900)
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

    public static function error()
    {
        $r = self::getInstance()->error();
        if ($r == '' && defined('DB_NODB') && !defined('DB_INSTALLER_STAGE4')) {
            return 'The database connection was never established';
        }
        return $r;
    }

    public static function fetchAssoc(&$result)
    {
        if (is_array($result)) {
            $val = current($result);
            next($result);
            return $val;
        }
        return self::getInstance()->fetchAssoc($result);
    }

    public static function insertId()
    {
        if (defined('DB_NODB') && !defined('LINK')) {
            return -1;
        }
        return self::getInstance()->insertId();
    }

    public static function numRows($result)
    {
        if (is_array($result)) {
            return count($result);
        }
        if (defined('DB_NODB') && !defined('LINK')) {
            return 0;
        }
        return self::getInstance()->numRows($result);
    }

    public static function affectedRows($link = false)
    {
        global $dbinfo;
        if (isset($dbinfo['affected_rows'])) {
            return $dbinfo['affected_rows'];
        }
        if (defined('DB_NODB') && !defined('LINK')) {
            return 0;
        }
        return self::getInstance()->affectedRows();
    }

    public static function pconnect($host, $user, $pass)
    {
        return self::getInstance()->pconnect($host, $user, $pass);
    }

    public static function connect($host, $user, $pass)
    {
        return self::getInstance()->connect($host, $user, $pass);
    }

    public static function getServerVersion()
    {
        return self::getInstance()->getServerVersion();
    }

    public static function selectDb($dbname)
    {
        return self::getInstance()->selectDb($dbname);
    }

    public static function escape($string)
    {
        return self::getInstance()->escape($string);
    }

    public static function freeResult($result)
    {
        if (is_array($result)) {
            unset($result);
            return true;
        }
        if (defined('DB_NODB') && !defined('LINK')) {
            return false;
        }
        self::getInstance()->freeResult($result);
        return true;
    }

    public static function tableExists($tablename)
    {
        if (defined('DB_NODB') && !defined('LINK')) {
            return false;
        }
        return self::getInstance()->tableExists($tablename);
    }

    public static function prefix($tablename, $force = false)
    {
        global $DB_PREFIX;
        if ($force === false) {
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

