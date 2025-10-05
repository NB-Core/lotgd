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

        $conn = Database::getDoctrineConnection();
        $sql  = sprintf(
            'INSERT INTO %s (message,category,severity,filed,date,who) VALUES (:message, :category, :severity, :filed, :date, :who)',
            Database::prefix('gamelog')
        );

        $conn->executeStatement($sql, [
            'message'  => $message,
            'category' => $category,
            'severity' => $severity,
            'filed'    => $filed ? 1 : 0,
            'date'     => date('Y-m-d H:i:s'),
            'who'      => $who,
        ]);
    }
}
