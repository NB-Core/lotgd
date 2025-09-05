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
$customFile   = __DIR__ . '/../../config/async.settings.php';

if (is_readable($defaultsFile)) {
    $defaults = require $defaultsFile;
} else {
    trigger_error(
        'Default asynchronous settings file missing; using internal defaults.',
        E_USER_WARNING
    );

    $defaults = [
        'mail_debug'                  => 0,
        'never_timeout_if_browser_open' => 0,
        'ajax_rate_limit_seconds'     => 1.0,
        'check_mail_timeout_seconds'  => 10,
        'check_timeout_seconds'       => 5,
        'start_timeout_show_seconds'  => 300,
        'clear_script_execution_seconds' => -1,
    ];
}

if (is_readable($customFile)) {
    $asyncSettings = array_merge($defaults, require $customFile);
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

if ($mail_debug == 1) {
    $checkMailTimeoutSeconds = 500;   // how often check for new mail
    $check_timeout_seconds = 5;          // how often checking for timeout
    $startTimeoutShowSeconds = 999;   // when should the counter start to display (time left)
    $clearScriptExecutionSeconds = -1; // when javascript should stop checking (ddos)
}

$timeout->setNeverTimeoutIfBrowserOpen($neverTimeoutIfBrowserOpen === 1);
$timeout->setStartTimeoutShowSeconds($startTimeoutShowSeconds);
$timeout->setCheckMailTimeoutSeconds($checkMailTimeoutSeconds);
$timeout->setClearScriptExecutionSeconds($clearScriptExecutionSeconds);

unset(
    $never_timeout_if_browser_open,
    $start_timeout_show_seconds,
    $check_mail_timeout_seconds,
    $clear_script_execution_seconds
);
