<?php

declare(strict_types=1);

/**
 * Simple wrapper around the gamelog table.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;

class GameLog
{
    private const ALLOWED_SEVERITIES = ['info', 'warning', 'error', 'debug'];

    /**
     * Insert a log message into the database.
     */
    public static function log(
        string $message,
        string $category = 'general',
        bool $filed = false,
        ?int $acctId = null,
        string $severity = 'info'
    ): void
    {
        global $session;
        $who = $acctId ?? (int) ($session['user']['acctid'] ?? 0);
        $severity = strtolower($severity);
        if (! in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            $severity = 'info';
        }

        $sql = 'INSERT INTO ' . Database::prefix('gamelog') .
            ' (message,category,severity,filed,date,who) VALUES (' .
            "'" . addslashes($message) . "','" . addslashes($category) . "','" . addslashes($severity) . "','" . ($filed ? "1" : "0") . "','" . date('Y-m-d H:i:s') . "','" . $who . "')";
        Database::query($sql);
    }
}
