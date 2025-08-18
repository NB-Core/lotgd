<?php

declare(strict_types=1);

/**
 * Load asynchronous configuration settings.
 *
 * This script loads user-defined async settings from the configuration
 * directory. If the user configuration file does not exist, it falls
 * back to the distributed defaults and logs a warning.
 */

$defaults = require __DIR__ . '/../../config/async.settings.php.dist';
$customFile = __DIR__ . '/../../config/async.settings.php';

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

if ($mail_debug == 1) {
    $check_mail_timeout_seconds = 500;   // how often check for new mail
    $check_timeout_seconds = 5;          // how often checking for timeout
    $start_timeout_show_seconds = 999;   // when should the counter start to display (time left)
    $clear_script_execution_seconds = -1; // when javascript should stop checking (ddos)
}
