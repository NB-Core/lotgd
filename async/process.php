<?php

declare(strict_types=1);

/**
 * Entry point for processing Jaxon AJAX requests. Loads the
 * lightweight environment, common settings and server callbacks,
 * then passes control to the Jaxon engine.
 */

// File ajax_process.php
define("OVERRIDE_FORCED_NAV", true);

require_once __DIR__ . '/common/bootstrap.php';
require_once __DIR__ . '/common/jaxon.php';

// Access the global jaxon instance and settings
global $jaxon, $ajax_rate_limit_seconds;

// Simple rate limiting for Jaxon requests.  If an Ajax request arrives less
// than the configured threshold after the previous one, we respond with HTTP 429 and skip
// executing the handler.  The timestamp is only updated when the request is
// accepted to avoid locking out legitimate retries.
if ($jaxon->canProcessRequest()) {
    $now       = microtime(true);
    $threshold = $ajax_rate_limit_seconds ?? 1.0; // from async settings with fallback

    if (isset($_SESSION['lastrequest']) && ($now - $_SESSION['lastrequest']) < $threshold) {
        http_response_code(429);
        echo 'Too Many Requests';
        exit;
    }

    $_SESSION['lastrequest'] = $now;

    try {
        $jaxon->processRequest();
    } catch (Exception $e) {
        error_log("Jaxon processing error: " . $e->getMessage());
        http_response_code(500);
        echo 'Server Error';
    }
} else {
    http_response_code(400);
    echo 'Bad Request';
}
