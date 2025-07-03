<?php
namespace Lotgd;

/**
 * Utility methods related to SQL operations.
 */
class Sql
{
    /**
     * Generate an output string describing the last SQL error.
     *
     * @param string $sql SQL statement that failed
     *
     * @return string Debug information
     */
    public static function error(string $sql): string
    {
        global $session;
        return OutputArray::output($session) . "SQL = <pre>$sql</pre>" . db_error(LINK);
    }
}
