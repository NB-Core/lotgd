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

/**
 * Emit a valid JSON/Jaxon-compatible error payload for async failures.
 *
 * Frontend callers expect parseable JSON and may surface hard SyntaxErrors when this endpoint
 * returns plain text or an empty body. Keep this contract stable across all failure branches.
 *
 * @param array<string, mixed> $payload
 */
function lotgd_async_emit_error_payload(int $statusCode, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode($payload) ?: '{"status":"error","error":"json_encode_failed"}';
}

// Simple rate limiting for Jaxon requests.  If an Ajax request arrives less
// than the configured threshold after the previous one, we respond with HTTP 429 and skip
// executing the handler.  The timestamp is only updated when the request is
// accepted to avoid locking out legitimate retries.
if ($jaxon->canProcessRequest()) {
    $now       = microtime(true);
    $threshold = $ajax_rate_limit_seconds ?? 1.0; // from async settings with fallback

    if (isset($_SESSION['lastrequest']) && ($now - $_SESSION['lastrequest']) < $threshold) {
        lotgd_async_emit_error_payload(429, [
            'status' => 'error',
            'error' => 'rate_limited',
            'message' => 'Too Many Requests',
        ]);
        exit;
    }

    $_SESSION['lastrequest'] = $now;

    try {
        $jaxon->processRequest();
    } catch (\Throwable $e) {
        error_log("Jaxon processing error: " . $e->getMessage());
        lotgd_async_emit_error_payload(500, [
            'status' => 'error',
            'error' => 'server_error',
            'message' => 'Server Error',
        ]);
    }
} else {
    lotgd_async_emit_error_payload(400, [
        'status' => 'error',
        'error' => 'bad_request',
        'message' => 'Bad Request',
    ]);
}
