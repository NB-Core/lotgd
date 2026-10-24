<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Doctrine\DBAL\ParameterType;
use Lotgd\Async\Response;
use Lotgd\GameLog;
use Lotgd\MySQL\Database;
use Lotgd\Security\Csrf;
use Lotgd\SecurityLog;
use Lotgd\Settings;

/**
 * The session timeout over the async endpoint: how long is left, and
 * keeping the session alive (docs/ReactiveCore.md, Session panel). The
 * values the panel needs on a page come from configured(), the same file the
 * async client reads (config/async.settings.php).
 */
class Timeout
{
    private static ?self $instance = null;

    private int $startTimeoutShowSeconds = 300;

    private bool $neverTimeoutIfBrowserOpen = false;

    private int $checkMailTimeoutSeconds = 10;

    private int $clearScriptExecutionSeconds = -1;

    private bool $configured = false;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * The instance with the timeout values of config/async.settings.php,
     * read quietly when async/common/settings.php has not run on this
     * request (a page without the async client).
     */
    public static function configured(): self
    {
        $instance = self::getInstance();
        if ($instance->configured) {
            return $instance;
        }
        $root = dirname(__DIR__, 4) . '/config/';
        $custom = defined('LOTGD_ASYNC_SETTINGS_FILE') ? (string) LOTGD_ASYNC_SETTINGS_FILE : $root . 'async.settings.php';
        $values = [];
        foreach ([$root . 'async.settings.php.dist', $custom] as $file) {
            if (is_readable($file)) {
                $read = require $file;
                $values = is_array($read) ? array_merge($values, $read) : $values;
            }
        }
        $instance->setNeverTimeoutIfBrowserOpen((int) ($values['never_timeout_if_browser_open'] ?? 0) === 1);
        $instance->setStartTimeoutShowSeconds((int) ($values['start_timeout_show_seconds'] ?? 300));

        return $instance;
    }

    public function setStartTimeoutShowSeconds(int $seconds): void
    {
        $this->configured = true;
        $this->startTimeoutShowSeconds = $seconds;
    }

    public function getStartTimeoutShowSeconds(): int
    {
        return $this->startTimeoutShowSeconds;
    }

    public function setNeverTimeoutIfBrowserOpen(bool $never): void
    {
        $this->neverTimeoutIfBrowserOpen = $never;
    }

    public function isNeverTimeoutIfBrowserOpen(): bool
    {
        return $this->neverTimeoutIfBrowserOpen;
    }

    public function setCheckMailTimeoutSeconds(int $seconds): void
    {
        $this->checkMailTimeoutSeconds = $seconds;
    }

    public function getCheckMailTimeoutSeconds(): int
    {
        return $this->checkMailTimeoutSeconds;
    }

    public function setClearScriptExecutionSeconds(int $seconds): void
    {
        $this->clearScriptExecutionSeconds = $seconds;
    }

    public function getClearScriptExecutionSeconds(): int
    {
        return $this->clearScriptExecutionSeconds;
    }

    /**
     * Seconds until a player whose last activity was $laston is logged out
     * (ForcedNavigation's rule); 0 once it has passed.
     */
    public static function remaining(string $laston, ?int $now = null): int
    {
        $last = strtotime($laston);
        if ($last === false || $last <= 0) {
            return 0;
        }
        $timeout = (int) Settings::getInstance()->getSetting('LOGINTIMEOUT', 900);

        return max(0, $last + $timeout - ($now ?? time()));
    }

    /**
     * Count now as the player's last activity, as a page would. For calls
     * the player made themselves (keepAlive(), a line posted, a duel move):
     * the async endpoint saves no account, so without this they would time
     * out while playing on one page.
     */
    public static function touch(int $acctid): int
    {
        global $session;

        if ($acctid <= 0) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('accounts') . ' SET laston = :laston WHERE acctid = :acctid',
            ['laston' => $now, 'acctid' => $acctid],
            ['laston' => ParameterType::STRING, 'acctid' => ParameterType::INTEGER]
        );
        if (isset($session['user']) && (int) ($session['user']['acctid'] ?? 0) === $acctid) {
            $session['user']['laston'] = $now;
        }

        return self::remaining($now);
    }

    /**
     * The session panel's "Stay logged in" (assets/js/lotgd.timeout.js):
     * counts as activity, like any page. It writes, so the async token is
     * checked here whatever csrf_mode says. Answers `session: {remaining}`;
     * 0 when the player is no longer logged in.
     */
    public function keepAlive(): Response
    {
        global $session;

        $response = new Response();
        $acctid = (int) ($session['user']['acctid'] ?? 0);
        if ($acctid <= 0 || empty($session['user']['loggedin'])) {
            return $response->data('session', ['remaining' => 0]);
        }
        if (!Csrf::matches(Csrf::SCOPE_ASYNC, Csrf::requestToken())) {
            SecurityLog::event('Keep-alive refused: async token does not match', [], $acctid, GameLog::SEVERITY_WARNING, false);

            return $response->data('session', ['remaining' => self::remaining((string) ($session['user']['laston'] ?? ''))]);
        }

        return $response->data('session', ['remaining' => self::touch($acctid)]);
    }

    /**
     * How long the player's session has left, for the session panel, in
     * data `session` (`{remaining}`): another tab may have kept it alive.
     * With never_timeout_if_browser_open the poll itself counts as activity.
     */
    public function timeoutStatus(bool $args = false): Response
    {
        global $session;

        $response = new Response();
        if ($args === false) {
            return $response;
        }

        if (!isset($session['user'])) {
            // ForcedNavigation emptied the session: it has timed out.
            $timedOut = (isset($session['loggedin']) && $session['loggedin'] === false)
                || (isset($session['message']) && stripos((string) $session['message'], 'Your session has expired!') !== false);

            return $timedOut ? $response->data('session', ['remaining' => 0]) : $response;
        }

        if ($this->isNeverTimeoutIfBrowserOpen()) {
            return $response->data('session', ['remaining' => self::touch((int) ($session['user']['acctid'] ?? 0))]);
        }

        return $response->data('session', ['remaining' => self::remaining((string) ($session['user']['laston'] ?? ''))]);
    }
}
