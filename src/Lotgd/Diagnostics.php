<?php

declare(strict_types=1);

/**
 * Read side of the log subsystem, for the megauser diagnostics page.
 *
 * Every log in this game already has a writer, and since the logging
 * consolidation each kind of message has one place it belongs. Reading them
 * back was still spread over five pages, and three sources had no reader at
 * all: the faillog table, the server's runtime state, and the question of
 * whether the code and the schema are the same version.
 *
 * This class answers "is everything still running?" from the sources that can
 * actually be read. It is deliberately read-only: nothing here writes, and
 * {@see self::runtime()} goes out of its way to keep it that way.
 */

namespace Lotgd;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;

class Diagnostics
{
    /** Time windows the page offers, in hours. */
    public const WINDOWS = [1, 6, 24, 72, 168, 720];

    public const DEFAULT_HOURS = 24;

    /** Per-source row cap, so one page view cannot run away on a busy server. */
    public const ROW_LIMIT = 200;

    /**
     * Extensions the runtime is expected to carry.
     *
     * Mirrors the list the container readiness probe checks
     * ({@see docker/health/ready.php}); that file lives outside the autoloaded
     * tree, so the list is repeated rather than imported.
     *
     * @var list<string>
     */
    private const EXPECTED_EXTENSIONS = ['gd', 'mbstring', 'mysqli', 'Zend OPcache', 'pdo', 'pdo_mysql', 'zip'];

    /**
     * Clamp a requested window to one this page offers.
     *
     * This is the input validation boundary for the whole page: anything that
     * is not exactly one of the offered windows becomes the default, so no
     * caller-supplied value ever reaches a query as anything but a bound
     * timestamp derived from here.
     */
    public static function normalizeHours(mixed $raw): int
    {
        if (! is_scalar($raw)) {
            return self::DEFAULT_HOURS;
        }

        $hours = (int) $raw;

        return in_array($hours, self::WINDOWS, true) ? $hours : self::DEFAULT_HOURS;
    }

    /**
     * Clamp a requested severity to the game log's vocabulary, or null for "all".
     */
    public static function normalizeSeverity(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $severity = strtolower($raw);
        $allowed = [
            GameLog::SEVERITY_INFO,
            GameLog::SEVERITY_WARNING,
            GameLog::SEVERITY_ERROR,
            GameLog::SEVERITY_DEBUG,
        ];

        return in_array($severity, $allowed, true) ? $severity : null;
    }

    /**
     * Start of the window as a comparable timestamp.
     */
    public static function since(int $hours): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . self::normalizeHours($hours) . ' hours'));
    }

    /**
     * Human label for a window, for navigation entries and headings.
     */
    public static function windowLabel(int $hours): string
    {
        return match ($hours) {
            1 => 'Last hour',
            6 => 'Last 6 hours',
            24 => 'Last 24 hours',
            72 => 'Last 3 days',
            168 => 'Last 7 days',
            720 => 'Last 30 days',
            default => sprintf('Last %d hours', $hours),
        };
    }

    /**
     * Game log entries in the window, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function gameLog(int $hours, ?string $severity, int $limit): array
    {
        $gamelog = Database::prefix('gamelog');
        $accounts = Database::prefix('accounts');
        $params = ['since' => self::since($hours)];
        $types = ['since' => ParameterType::STRING];

        $severityClause = '';
        if ($severity !== null) {
            $severityClause = ' AND g.severity = :severity';
            $params['severity'] = $severity;
            $types['severity'] = ParameterType::STRING;
        }

        $sql = "SELECT g.logid, g.date, g.category, g.severity, g.message, g.who, a.name AS name"
            . " FROM {$gamelog} g LEFT JOIN {$accounts} a ON g.who = a.acctid"
            . " WHERE g.date > :since" . $severityClause
            . " ORDER BY g.date DESC LIMIT " . $this->cap($limit);

        try {
            return Database::getDoctrineConnection()->fetchAllAssociative($sql, $params, $types);
        } catch (\Throwable $exception) {
            // `severity` arrived with a migration, and every query above names
            // it -- in the SELECT list even when no filter was asked for. An
            // installation that has not run that migration must still get a
            // usable page rather than a fatal, so fall back to the shape that
            // predates the column, whether or not a filter was requested.
            $fallback = "SELECT g.logid, g.date, g.category, g.message, g.who, a.name AS name"
                . " FROM {$gamelog} g LEFT JOIN {$accounts} a ON g.who = a.acctid"
                . " WHERE g.date > :since"
                . " ORDER BY g.date DESC LIMIT " . $this->cap($limit);

            return Database::getDoctrineConnection()->fetchAllAssociative(
                $fallback,
                ['since' => $params['since']],
                ['since' => ParameterType::STRING]
            );
        }
    }

    /**
     * Character audit entries in the window, across both debug log tables.
     *
     * The new day routine moves the entire contents of `debuglog` into
     * `debuglog_archive` every time it runs, with a cutoff of "now". Reading
     * only the live table would therefore lose everything written before the
     * last new day, which for a 24 hour window is most of it.
     *
     * @return list<array<string,mixed>>
     */
    public function debugLog(int $hours, int $limit): array
    {
        $live = Database::prefix('debuglog');
        $archive = Database::prefix('debuglog_archive');
        $since = self::since($hours);

        // Two placeholder names for one value: a repeated named placeholder is
        // not safe with PDO unless emulated prepares are on.
        $sql = "SELECT id, date, actor, target, message, field, value, 'live' AS origin"
            . " FROM {$live} WHERE date > :since_live"
            . " UNION ALL"
            . " SELECT id, date, actor, target, message, field, value, 'archive' AS origin"
            . " FROM {$archive} WHERE date > :since_archive"
            . " ORDER BY date DESC LIMIT " . $this->cap($limit);

        $rows = Database::getDoctrineConnection()->fetchAllAssociative(
            $sql,
            ['since_live' => $since, 'since_archive' => $since],
            ['since_live' => ParameterType::STRING, 'since_archive' => ParameterType::STRING]
        );

        return $this->attachAccountNames($rows, ['actor', 'target']);
    }

    /**
     * Failed login attempts in the window.
     *
     * The column list is explicit and `post` is not in it, deliberately: that
     * column holds a serialize() of the whole POST body of a failed login, so
     * it contains submitted passwords. It is never selected, never rendered and
     * never passed on -- not even to a megauser. DiagnosticsFaillogPostRegressionTest
     * holds that line.
     *
     * @return list<array<string,mixed>>
     */
    public function failLog(int $hours, int $limit): array
    {
        $faillog = Database::prefix('faillog');
        $accounts = Database::prefix('accounts');

        $sql = "SELECT f.eventid, f.date, f.ip, f.acctid, a.name AS name, a.login AS login, a.superuser AS superuser"
            . " FROM {$faillog} f LEFT JOIN {$accounts} a ON f.acctid = a.acctid"
            . " WHERE f.date > :since"
            . " ORDER BY f.date DESC LIMIT " . $this->cap($limit);

        return Database::getDoctrineConnection()->fetchAllAssociative(
            $sql,
            ['since' => self::since($hours)],
            ['since' => ParameterType::STRING]
        );
    }

    /**
     * Aggregated runtime samples from the profiling table.
     *
     * @param string $type Either `pagegentime` or `hooktime`.
     *
     * @return list<array<string,mixed>>
     */
    public function profiling(int $hours, string $type, int $limit): array
    {
        $debug = Database::prefix('debug');

        // ORDER BY is a constant here on purpose: debug.php interpolates a
        // request value into its ORDER BY, and that is not a pattern to copy.
        $sql = "SELECT type, category, subcategory, COUNT(id) AS hits,"
            . " SUM(value + 0) AS total, AVG(value + 0) AS mean"
            . " FROM {$debug} WHERE date > :since AND type = :type"
            . " GROUP BY type, category, subcategory"
            . " ORDER BY total DESC LIMIT " . $this->cap($limit);

        return Database::getDoctrineConnection()->fetchAllAssociative(
            $sql,
            ['since' => self::since($hours), 'type' => $type],
            ['since' => ParameterType::STRING, 'type' => ParameterType::STRING]
        );
    }

    /**
     * Row counts per source, so the page can say "showing 200 of 4312".
     *
     * @return array<string,int>
     */
    public function counts(int $hours): array
    {
        $since = self::since($hours);
        $connection = Database::getDoctrineConnection();
        $counts = [];

        $sources = [
            'gamelog' => [Database::prefix('gamelog'), 'date'],
            'faillog' => [Database::prefix('faillog'), 'date'],
            'debug' => [Database::prefix('debug'), 'date'],
        ];

        foreach ($sources as $key => [$table, $column]) {
            $counts[$key] = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM {$table} WHERE {$column} > :since",
                ['since' => $since],
                ['since' => ParameterType::STRING]
            );
        }

        // Both halves of the character audit trail, for the same reason the
        // reader above reads both.
        $counts['debuglog'] = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Database::prefix('debuglog') . ' WHERE date > :since',
            ['since' => $since],
            ['since' => ParameterType::STRING]
        ) + (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Database::prefix('debuglog_archive') . ' WHERE date > :since',
            ['since' => $since],
            ['since' => ParameterType::STRING]
        );

        return $counts;
    }

    /**
     * One chronological stream of the events worth noticing.
     *
     * Fed by exactly two sources: game log entries that are security events or
     * carry a warning/error severity, and failed logins. The character audit
     * trail and the profiling samples are deliberately left out -- they are
     * high volume and would bury the signal.
     *
     * @return list<array{at:string,source:string,severity:string,category:string,actor:string,text:string}>
     */
    public function timeline(int $hours, int $limit): array
    {
        $events = [];

        foreach ($this->gameLog($hours, null, $limit) as $row) {
            $severity = strtolower((string) ($row['severity'] ?? GameLog::SEVERITY_INFO));
            $category = (string) ($row['category'] ?? '');
            $isSecurity = $category === GameLog::CATEGORY_SECURITY;
            $isTrouble = in_array($severity, [GameLog::SEVERITY_WARNING, GameLog::SEVERITY_ERROR], true);

            if (! $isSecurity && ! $isTrouble) {
                continue;
            }

            $events[] = [
                'at' => (string) $row['date'],
                'source' => 'gamelog',
                'severity' => $severity,
                'category' => $category,
                'actor' => (string) ($row['name'] ?? ''),
                'text' => (string) $row['message'],
            ];
        }

        foreach ($this->failLog($hours, $limit) as $row) {
            $who = (string) ($row['login'] ?? '');
            $events[] = [
                'at' => (string) $row['date'],
                'source' => 'faillog',
                'severity' => GameLog::SEVERITY_WARNING,
                'category' => 'login',
                'actor' => (string) ($row['name'] ?? ''),
                'text' => sprintf(
                    'Failed login from %s%s',
                    (string) ($row['ip'] ?? 'unknown'),
                    $who === '' ? '' : ' for account ' . $who
                ),
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $this->cap($limit));
    }

    /**
     * The runtime snapshot: version, environment, cron and configuration state.
     *
     * The settings map is taken once through getArray() rather than key by key.
     * Roughly twenty values are reported here, so one read beats twenty, and
     * every row in the snapshot then describes the same moment.
     *
     * It also keeps the page from being the thing that materialises what it
     * reports on: getSetting() stores the default when a key is missing, which
     * is how a fresh installation fills its settings table, but a page whose
     * only job is to describe the current state is a poor place for that to
     * happen.
     *
     * @return array<string,list<array{label:string,value:string,status:string}>>
     */
    public function runtime(): array
    {
        $settings = Settings::hasInstance() ? Settings::getInstance()->getArray() : [];

        return [
            'Version' => $this->versionRows($settings),
            'Environment' => $this->environmentRows(),
            'Maintenance' => $this->maintenanceRows($settings),
            'Logging' => $this->loggingRows($settings),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     *
     * @return list<array{label:string,value:string,status:string}>
     */
    private function versionRows(array $settings): array
    {
        $code = Page::getInstance()->getLogdVersion();
        $schema = (string) ($settings['installer_version'] ?? '');
        $rows = [
            $this->row('Game version', $code),
            $this->row(
                'Schema version',
                $schema === '' ? 'unknown' : $schema,
                // A mismatch is a finding in itself: common.php diverts to the
                // installer when these disagree.
                ($schema !== '' && $schema === $code) ? 'ok' : 'warn'
            ),
        ];

        try {
            $rows[] = $this->row('Database server', Database::getServerVersion());
        } catch (\Throwable $exception) {
            $rows[] = $this->row('Database server', 'unknown', 'unknown');
        }

        // Read the cache the core news page fills; never trigger a fetch here.
        $release = DataCache::getInstance()->datacache('github_release_latest', 86400);
        if (is_array($release) && isset($release['tag_name'])) {
            $rows[] = $this->row('Latest upstream release', (string) $release['tag_name']);
        } else {
            $rows[] = $this->row('Latest upstream release', 'not cached', 'unknown');
        }

        return $rows;
    }

    /**
     * @return list<array{label:string,value:string,status:string}>
     */
    private function environmentRows(): array
    {
        $missing = [];
        foreach (self::EXPECTED_EXTENSIONS as $extension) {
            if (! extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        return [
            $this->row('PHP version', PHP_VERSION),
            $this->row(
                'Missing extensions',
                $missing === [] ? 'none' : implode(', ', $missing),
                $missing === [] ? 'ok' : 'error'
            ),
            $this->row('memory_limit', (string) ini_get('memory_limit')),
            $this->row('max_execution_time', (string) ini_get('max_execution_time')),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     *
     * @return list<array{label:string,value:string,status:string}>
     */
    private function maintenanceRows(array $settings): array
    {
        $rows = [];

        $online = (int) ($settings['OnlineCount'] ?? 0);
        $onlineLast = (int) ($settings['OnlineCountLast'] ?? 0);
        $maxOnline = (int) ($settings['maxonline'] ?? 0);
        $rows[] = $this->row(
            'Players online',
            sprintf(
                '%d%s (%s)',
                $online,
                $maxOnline > 0 ? ' of ' . $maxOnline : '',
                $onlineLast > 0 ? $this->ageLabel(time() - $onlineLast) . ' old' : 'never counted'
            )
        );

        // Written before the work runs, and also bumped by a player-triggered
        // new day, so it only means "cron ran" when newdaycron is on. It is
        // stored with gmdate(), so it has to be read back as UTC.
        $semaphore = (string) ($settings['newdaySemaphore'] ?? '');
        $cronEnabled = (int) ($settings['newdaycron'] ?? 0) === 1;
        if ($semaphore === '') {
            $rows[] = $this->row('Last new day', 'never', 'warn');
        } else {
            $age = time() - (int) strtotime($semaphore . ' +0000');
            $rows[] = $this->row(
                'Last new day',
                $semaphore . ' UTC (' . $this->ageLabel($age) . ' ago)'
                    . ($cronEnabled ? '' : ' -- newdaycron is off, so this may be player-triggered'),
                $age > 26 * 3600 ? 'warn' : 'ok'
            );
        }

        // The better completion signal: these rows are written after the work,
        // unlike newdaySemaphore and lastdboptimize which are set before it.
        $lastMaintenance = $this->lastMaintenanceRun();
        if ($lastMaintenance === null) {
            $rows[] = $this->row('Last maintenance run', 'no record in the game log', 'warn');
        } else {
            $age = time() - (int) strtotime($lastMaintenance);
            $rows[] = $this->row(
                'Last maintenance run',
                $lastMaintenance . ' (' . $this->ageLabel($age) . ' ago)',
                $age > 26 * 3600 ? 'warn' : 'ok'
            );
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $settings
     *
     * @return list<array{label:string,value:string,status:string}>
     */
    private function loggingRows(array $settings): array
    {
        $target = (string) ini_get('error_log');
        $readable = $target !== '' && is_file($target) && is_readable($target);
        $rows = [
            $this->row(
                'PHP error log',
                $target === ''
                    ? 'the web server log'
                    : $target . ($readable ? '' : ' -- not readable from PHP, use the container log'),
                $readable ? 'ok' : 'unknown'
            ),
        ];

        $debugMode = (int) ($settings['debug'] ?? 0) === 1;
        $rows[] = $this->row(
            'DEBUG mode',
            $debugMode ? 'on -- runtimes are being collected' : 'off -- no runtimes are collected',
            $debugMode ? 'warn' : 'ok'
        );

        // The one setting on this page that is a live risk rather than a fact.
        $detailsPublic = (int) ($settings['show_error_details'] ?? 0) === 1;
        $rows[] = $this->row(
            'Public error details',
            $detailsPublic
                ? 'ON -- every visitor sees messages, paths and backtraces'
                : 'off',
            $detailsPublic ? 'error' : 'ok'
        );

        foreach (
            [
                'expiregamelog' => 'Game log retention',
                'expiredebuglog' => 'Debug log retention',
                'expirefaillog' => 'Fail log retention',
                'expiredebug' => 'DEBUG runtime retention',
            ] as $key => $label
        ) {
            $days = (int) ($settings[$key] ?? 0);
            $rows[] = $this->row($label, $days > 0 ? $days . ' days' : 'kept indefinitely');
        }

        return $rows;
    }

    /**
     * Newest maintenance entry in the game log, or null when there is none.
     */
    private function lastMaintenanceRun(): ?string
    {
        $value = Database::getDoctrineConnection()->fetchOne(
            'SELECT MAX(date) FROM ' . Database::prefix('gamelog') . ' WHERE category = :category',
            ['category' => GameLog::CATEGORY_MAINTENANCE],
            ['category' => ParameterType::STRING]
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Resolve account ids in the given columns to display names.
     *
     * Done in one follow-up query rather than by joining inside each half of a
     * UNION: joining there is what forced the CAST(... AS CHAR) workarounds in
     * the existing debug log viewer.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $columns
     *
     * @return list<array<string,mixed>>
     */
    private function attachAccountNames(array $rows, array $columns): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $id = (int) ($row[$column] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        $names = [];
        if ($ids !== []) {
            $found = Database::getDoctrineConnection()->fetchAllAssociative(
                'SELECT acctid, name FROM ' . Database::prefix('accounts') . ' WHERE acctid IN (:ids)',
                ['ids' => array_keys($ids)],
                ['ids' => ArrayParameterType::INTEGER]
            );
            foreach ($found as $account) {
                $names[(int) $account['acctid']] = (string) $account['name'];
            }
        }

        foreach ($rows as $index => $row) {
            foreach ($columns as $column) {
                $id = (int) ($row[$column] ?? 0);
                $rows[$index][$column . 'name'] = $names[$id] ?? '';
            }
        }

        return $rows;
    }

    /**
     * Keep a caller-supplied limit inside something a page view can render.
     */
    private function cap(int $limit): int
    {
        return max(1, min($limit, self::ROW_LIMIT));
    }

    /**
     * @return array{label:string,value:string,status:string}
     */
    private function row(string $label, string $value, string $status = 'ok'): array
    {
        return ['label' => $label, 'value' => $value, 'status' => $status];
    }

    /**
     * Render a duration in seconds as a short human label.
     */
    private function ageLabel(int $seconds): string
    {
        if ($seconds < 0) {
            return 'in the future';
        }
        if ($seconds < 90) {
            return $seconds . 's';
        }
        if ($seconds < 5400) {
            return round($seconds / 60) . 'm';
        }
        if ($seconds < 172800) {
            return round($seconds / 3600) . 'h';
        }

        return round($seconds / 86400) . 'd';
    }
}
