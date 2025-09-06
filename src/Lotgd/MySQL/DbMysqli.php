<?php

declare(strict_types=1);

/**
 * MySQLi helper used by the database wrapper.
 */

namespace Lotgd\MySQL;

use Lotgd\DataCache;

class DbMysqli
{
    /** @var \mysqli|null */
    protected ?\mysqli $link = null;

    /**
     * Open a connection to the database server.
     */
    public function connect(string $host, string $user, string $pass): bool
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

    /**
     * Open a persistent connection to the database server.
     */
    public function pconnect(string $host, string $user, string $pass): bool
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

    /**
     * Select a database.
     */
    public function selectDb(string $dbname): bool
    {
        return mysqli_select_db($this->link, $dbname);
    }

    /**
     * Set the client character set.
     */
    public function setCharset(string $charset): bool
    {
        return mysqli_set_charset($this->link, $charset);
    }

    /**
     * Execute a query on the current connection.
     */
    public function query(string $sql): \mysqli_result|bool
    {
        return mysqli_query($this->link, $sql);
    }

    /**
     * Fetch an associative row from a result set.
     */
    public function fetchAssoc(\mysqli_result $result): array|null
    {
        return mysqli_fetch_assoc($result);
    }

    /**
     * Get the last inserted ID for this connection.
     */
    public function insertId(): int|string
    {
        return mysqli_insert_id($this->link);
    }

    /**
     * Count rows from a result set.
     */
    public function numRows(\mysqli_result $result): int
    {
        return mysqli_num_rows($result);
    }

    /**
     * Get the number of rows affected by the previous query.
     */
    public function affectedRows(): int
    {
        return mysqli_affected_rows($this->link);
    }

    /**
     * Retrieve the last error message.
     */
    public function error(): string
    {
        return mysqli_error($this->link);
    }

    /**
     * Escape a string for use in a query.
     */
    public function escape(string $string): string
    {
        return mysqli_real_escape_string($this->link, $string);
    }

    /**
     * Free a result set.
     */
    public function freeResult(\mysqli_result $result): bool
    {
        mysqli_free_result($result); //returns always void
        return true; // for compatibility with the interface
    }

    /**
     * Check whether the given table exists.
     */
    public function tableExists(string $tablename): bool
    {
        try {
            $result = $this->query("SHOW TABLES LIKE '$tablename'");
        } catch (\mysqli_sql_exception $exception) {
            if (defined('IS_INSTALLER') && IS_INSTALLER && class_exists('Lotgd\\Installer\\InstallerLogger')) {
                \Lotgd\Installer\InstallerLogger::log($exception->getMessage());
            }

            return false;
        }

        return ($result && mysqli_num_rows($result) > 0);
    }

    /**
     * Get the server version string.
     */
    public function getServerVersion(): string
    {
        return mysqli_get_server_info($this->link);
    }
}
