<?php

declare(strict_types=1);

/**
 * Utility methods related to SQL operations.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;

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
        return OutputArray::output($session) . "SQL = <pre>$sql</pre>" . Database::error();
    }
}
