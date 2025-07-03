<?php
namespace Lotgd\MySQL;

use Lotgd\DataCache;

/**
 * MySQLi helper used by the database wrapper.
 */
class DbMysqli
{
    protected $link;

    public function connect($host, $user, $pass)
    {
        $this->link = mysqli_connect($host, $user, $pass);

        if (!$this->link) {
            $error = mysqli_connect_error();
            if (defined('IS_INSTALLER') && IS_INSTALLER && class_exists('Lotgd\\Installer\\InstallerLogger')) {
                \Lotgd\Installer\InstallerLogger::log($error);
            }
            echo $error;
        }

        return $this->link ? true : false;
    }

    public function pconnect($host, $user, $pass)
    {
        $this->link = mysqli_connect($host, $user, $pass);

        if (!$this->link) {
            $error = mysqli_connect_error();
            if (defined('IS_INSTALLER') && IS_INSTALLER && class_exists('Lotgd\\Installer\\InstallerLogger')) {
                \Lotgd\Installer\InstallerLogger::log($error);
            }
            echo $error;
        }

        return $this->link ? true : false;
    }

    public function selectDb($dbname)
    {
        return mysqli_select_db($this->link, $dbname);
    }

    public function setCharset($charset)
    {
        return mysqli_set_charset($this->link, $charset);
    }

    public function query($sql)
    {
        return mysqli_query($this->link, $sql);
    }

    public function fetchAssoc($result)
    {
        return mysqli_fetch_assoc($result);
    }

    public function insertId()
    {
        return mysqli_insert_id($this->link);
    }

    public function numRows($result)
    {
        return mysqli_num_rows($result);
    }

    public function affectedRows()
    {
        return mysqli_affected_rows($this->link);
    }

    public function error()
    {
        return mysqli_error($this->link);
    }

    public function escape($string)
    {
        return mysqli_real_escape_string($this->link, $string);
    }

    public function freeResult($result)
    {
        return mysqli_free_result($result);
    }

    public function tableExists($tablename)
    {
        $result = $this->query("SHOW TABLES LIKE '$tablename'");
        return ($result && mysqli_num_rows($result) > 0);
    }

    public function getServerVersion()
    {
        return mysqli_get_server_info($this->link);
    }
}

