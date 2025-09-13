<?php

declare(strict_types=1);

/**
 * Simple wrapper around the gamelog table.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;

class GameLog
{
    /**
     * Insert a log message into the database.
     */
    public static function log(string $message, string $category = 'general', bool $filed = false, ?int $acctId = null): void
    {
        global $session;
        $who = $acctId ?? (int) ($session['user']['acctid'] ?? 0);
        $sql = 'INSERT INTO ' . Database::prefix('gamelog') .
            ' (message,category,filed,date,who) VALUES (' .
            "'" . addslashes($message) . "','" . addslashes($category) . "','" . ($filed ? "1" : "0") . "','" . date('Y-m-d H:i:s') . "','" . $who . "')";
        Database::query($sql);
    }
}
