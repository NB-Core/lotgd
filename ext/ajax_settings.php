<?php

declare(strict_types=1);

/**
 * Load asynchronous configuration for Ajax features.
 *
 * Values are read from `config/async.settings.php` if present,
 * otherwise the defaults from `config/async.settings.php.dist` are used.
 * Copy the `.dist` file to `config/async.settings.php` to customize these settings.
 */

$defaults = require __DIR__ . '/../config/async.settings.php.dist';
$customFile = __DIR__ . '/../config/async.settings.php';

if (is_readable($customFile)) {
    $settings = array_merge($defaults, require $customFile);
} else {
    $settings = $defaults;
}

extract($settings, EXTR_SKIP);

if ($mail_debug == 1) {
    $check_mail_timeout_seconds = 500;  // how often check for new mail
    $check_timeout_seconds = 5;         // how often checking for timeout
    $start_timeout_show_seconds = 999;  // when should the counter start to display (time left)
    $clear_script_execution_seconds = -1; // when javascript should stop checking (ddos)
}
