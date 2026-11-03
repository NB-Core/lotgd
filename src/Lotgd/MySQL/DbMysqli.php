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
     * What the link was opened with, so that a released link can be opened
     * again when something still needs it (see release()).
     *
     * @var array{host:string,user:string,pass:string}|null
     */
    private ?array $credentials = null;

    private ?string $database = null;

    private ?string $charset = null;

    /**
     * Close the link for good while the request goes on.
     *
     * A server-sent event stream (async/stream.php) works through Doctrine's
     * connection alone and stays open for most of a minute; holding this one
     * as well doubled the connections each streaming player kept. Should code
     * after this still use it, link() opens it again with what it was opened
     * with before.
     */
    public function release(): void
    {
        if ($this->link instanceof \mysqli) {
            try {
                mysqli_close($this->link);
            } catch (\Throwable) {
                // Already gone: nothing to close.
            }
        }
        $this->link = null;
    }

    /**
     * The open link; after release(), opened again on first use.
     *
     * @throws \RuntimeException When there is no link and it cannot be opened
     *                           (never connected, or the reconnect failed).
     */
    private function link(): \mysqli
    {
        if ($this->link === null && $this->credentials !== null) {
            $link = mysqli_connect($this->credentials['host'], $this->credentials['user'], $this->credentials['pass']);
            if ($link instanceof \mysqli) {
                if ($this->database !== null) {
                    mysqli_select_db($link, $this->database);
                }
                if ($this->charset !== null) {
                    mysqli_set_charset($link, $this->charset);
                }
                $this->link = $link;
            }
        }
        if (!$this->link instanceof \mysqli) {
            throw new \RuntimeException(
                $this->credentials === null
                    ? 'The legacy database link is not open.'
                    : 'The legacy database link could not be opened again: ' . (string) mysqli_connect_error()
            );
        }

        return $this->link;
    }

    /**
     * Open a connection to the database server.
     */
    public function connect(string $host, string $user, string $pass): bool
    {
        $this->link = mysqli_connect($host, $user, $pass);
        $this->credentials = $this->link ? ['host' => $host, 'user' => $user, 'pass' => $pass] : null;

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
        $this->credentials = $this->link ? ['host' => $host, 'user' => $user, 'pass' => $pass] : null;

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
        $selected = mysqli_select_db($this->link(), $dbname);
        if ($selected) {
            $this->database = $dbname;
        }

        return $selected;
    }

    /**
     * Set the client character set.
     */
    public function setCharset(string $charset): bool
    {
        $set = mysqli_set_charset($this->link(), $charset);
        if ($set) {
            $this->charset = $charset;
        }

        return $set;
    }

    /**
     * Execute a query on the current connection.
     */
    public function query(string $sql): \mysqli_result|bool
    {
        return mysqli_query($this->link(), $sql);
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
        return mysqli_insert_id($this->link());
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
        return mysqli_affected_rows($this->link());
    }

    /**
     * Retrieve the last error message.
     */
    public function error(): string
    {
        return mysqli_error($this->link());
    }

    /**
     * Escape a string for use in a query.
     */
    public function escape(string $string): string
    {
        return mysqli_real_escape_string($this->link(), $string);
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
        return mysqli_get_server_info($this->link());
    }
}
