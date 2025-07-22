<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;

/**
 * Simple wrapper around the gamelog table.
 */
class GameLog
{
    /**
     * Insert a log message into the database.
     */
    public static function log(string $message, string $category = 'general', bool $filed = false): void
    {
        global $session;
        $sql = 'INSERT INTO ' . Database::prefix('gamelog') .
            ' (message,category,filed,date,who) VALUES (' .
            "'" . addslashes($message) . "','" . addslashes($category) . "','" . ($filed ? "1" : "0") . "','" . date('Y-m-d H:i:s') . "','" . ((int)($session['user']['acctid'] ?? 0)) . "')";
        Database::query($sql);
    }
}
