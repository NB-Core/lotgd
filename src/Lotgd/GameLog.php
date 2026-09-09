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
     * Severity levels understood by gamelog.php's filter.
     *
     * A failure is expressed here, never by forking the category: an operator
     * filtering for "expiration" must see the successes and the failures in one
     * list, and filtering for "error" must find every failure regardless of the
     * subsystem that produced it.
     */
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_DEBUG = 'debug';

    /**
     * Closed category vocabulary.
     *
     * Categories name the subsystem an event belongs to, so that gamelog.php's
     * "View by category" navigation stays a short, stable list. Modules may still
     * pass their own category string; core code must use one of these constants.
     */
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_MAINTENANCE = 'maintenance';
    public const CATEGORY_EXPIRATION = 'expiration';
    public const CATEGORY_MODULES = 'modules';
    public const CATEGORY_USERS = 'user management';
    public const CATEGORY_SETTINGS = 'settings';
    public const CATEGORY_CLAN = 'clan';
    public const CATEGORY_BATTLE = 'battle';
    public const CATEGORY_CACHE = 'cache';

    private const ALLOWED_SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_WARNING,
        self::SEVERITY_ERROR,
        self::SEVERITY_DEBUG,
    ];

    /**
     * Insert a log message into the database.
     */
    public static function log(
        string $message,
        string $category = self::CATEGORY_GENERAL,
        bool $filed = false,
        ?int $acctId = null,
        string $severity = self::SEVERITY_INFO
    ): void
    {
        global $session;
        $who = $acctId ?? (int) ($session['user']['acctid'] ?? 0);
        $severity = strtolower($severity);
        if (! in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            $severity = self::SEVERITY_INFO;
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
