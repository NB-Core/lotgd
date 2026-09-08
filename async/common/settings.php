<?php

declare(strict_types=1);

/**
 * Load asynchronous configuration settings.
 *
 * This script loads user-defined async settings from the configuration
 * directory. If the user configuration file does not exist, it falls
 * back to the distributed defaults and logs a warning.
 */

$defaultsFile = __DIR__ . '/../../config/async.settings.php.dist';

/*
 * LOTGD_ASYNC_SETTINGS_FILE lets tests point at a fixture instead of the real
 * config/async.settings.php, which is gitignored and would otherwise have to be
 * written and deleted by the test run. Mirrors the LOTGD_ASYNC_PROCESS_TEST_MODE
 * seam in async/process.php. Unset in normal operation.
 */
$customFile = defined('LOTGD_ASYNC_SETTINGS_FILE')
    ? (string) LOTGD_ASYNC_SETTINGS_FILE
    : __DIR__ . '/../../config/async.settings.php';

if (is_readable($defaultsFile)) {
    $defaults = require $defaultsFile;
} else {
    trigger_error(
        'Default asynchronous settings file missing; using internal defaults.',
        E_USER_WARNING
    );

    $defaults = [
        'debug_console'               => 0,
        'csrf_mode'                   => 'log',
        'never_timeout_if_browser_open' => 0,
        'ajax_rate_limit_seconds'     => 1.0,
        'check_mail_timeout_seconds'  => 10,
        'check_timeout_seconds'       => 5,
        'start_timeout_show_seconds'  => 300,
        'clear_script_execution_seconds' => -1,
    ];
}

if (is_readable($customFile)) {
    $customSettings = require $customFile;
    if (!is_array($customSettings)) {
        $customSettings = [];
    }

    /*
     * Legacy alias. `mail_debug` is only consulted when the file has not been migrated
     * to `debug_console` yet -- the defaults always carry `debug_console`, so this has
     * to be resolved against the custom file rather than against the merged result.
     */
    if (!array_key_exists('debug_console', $customSettings) && array_key_exists('mail_debug', $customSettings)) {
        $customSettings['debug_console'] = $customSettings['mail_debug'];
    }

    $asyncSettings = array_merge($defaults, $customSettings);
    unset($customSettings);
} else {
    trigger_error(
        'Custom asynchronous settings file missing; using defaults.',
        E_USER_WARNING
    );
    $asyncSettings = $defaults;
}

extract($asyncSettings, EXTR_SKIP);

$timeout = \Lotgd\Async\Handler\Timeout::getInstance();

$neverTimeoutIfBrowserOpen = (int) ($never_timeout_if_browser_open ?? 0);
$startTimeoutShowSeconds = (int) ($start_timeout_show_seconds ?? 300);
$checkMailTimeoutSeconds = (int) ($check_mail_timeout_seconds ?? 10);
$clearScriptExecutionSeconds = (int) ($clear_script_execution_seconds ?? -1);

/*
 * `debug_console` only turns on verbose client-side logging; it deliberately leaves
 * every interval alone.
 *
 * Its predecessor `mail_debug` did something else entirely: it overrode
 * check_mail_timeout_seconds with 500, which is a poll interval in *seconds*. Turning
 * the flag on therefore throttled polling from every 10 seconds to every 8 minutes --
 * the opposite of what a debug switch is expected to do, and hard to tell apart from a
 * broken async layer. The old key is still read so existing configuration files keep
 * working, but it no longer changes any timing.
 */
$debugConsole = $debug_console ?? 0;
\Lotgd\Async\DebugMode::setEnabled((int) $debugConsole === 1);

// Unknown values resolve to 'log' inside setMode(), so a typo here neither
// disables the check nor starts rejecting live traffic.
\Lotgd\Async\CsrfMode::setMode((string) ($csrf_mode ?? \Lotgd\Async\CsrfMode::LOG));

$timeout->setNeverTimeoutIfBrowserOpen($neverTimeoutIfBrowserOpen === 1);
$timeout->setStartTimeoutShowSeconds($startTimeoutShowSeconds);
$timeout->setCheckMailTimeoutSeconds($checkMailTimeoutSeconds);
$timeout->setClearScriptExecutionSeconds($clearScriptExecutionSeconds);

unset(
    $never_timeout_if_browser_open,
    $start_timeout_show_seconds,
    $check_mail_timeout_seconds,
    $clear_script_execution_seconds,
    $debug_console,
    $mail_debug,
    $debugConsole,
    $csrf_mode
);
