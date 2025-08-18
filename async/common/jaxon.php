<?php

declare(strict_types=1);

/**
 * Common bootstrap for AJAX operations. This file configures the
 * Jaxon library and registers callable functions used by the client
 * side scripts.
 */

require_once __DIR__ . '/../../autoload.php'; // Start autoload

use Jaxon\Jaxon;                      // Use the jaxon core class
use Jaxon\Response\Response;          // and the Response class
use function Jaxon\jaxon;

// Load asynchronous configuration settings
$defaults = require __DIR__ . '/../../config/async.settings.php.dist';
$customFile = __DIR__ . '/../../config/async.settings.php';

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
global $jaxon;
// Get the Jaxon singleton object
$jaxon = jaxon();

// Set the Jaxon request processing URI
$jaxon->setOption('core.request.uri', 'async/process.php');

//$jaxon->setOption('core.debug.on',1);
//$jaxon->setOption('core.debug.verbose',1);


// Register an instance of the class with Jaxon
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'mail_status');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'commentary_text');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'timeout_status');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'commentary_refresh');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'poll_updates');
