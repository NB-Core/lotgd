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
 * Generate a correlation id for async diagnostics and log stitching.
 *
 * @return string Correlation identifier for request/diagnostic stitching.
 */
function lotgd_async_correlation_id(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (\Throwable) {
        return uniqid('diag_', true);
    }
}

/**
 * Emit a valid JSON/Jaxon-compatible error payload for async failures.
 *
 * Frontend callers expect parseable JSON and may surface hard SyntaxErrors when this endpoint
 * returns plain text or an empty body. Keep this contract stable across all failure branches.
 *
 * @param int                   $statusCode HTTP status code to send with the response.
 * @param array<string, mixed>  $payload    Structured error payload to JSON-encode.
 *
 * @return void
 */
function lotgd_async_emit_error_payload(int $statusCode, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode($payload) ?: '{"status":"error","error":"json_encode_failed"}';
}

/**
 * Check whether the current user has megauser diagnostics privileges.
 */
function lotgd_async_is_megauser(): bool
{
    global $session;

    $superuserFlags = 0;

    if (isset($session['user']['superuser'])) {
        $superuserFlags = (int) $session['user']['superuser'];
    } elseif (isset($_SESSION['session']['user']['superuser'])) {
        // Fallback to game session stored under $_SESSION['session']
        $superuserFlags = (int) $_SESSION['session']['user']['superuser'];
    } elseif (isset($_SESSION['user']['superuser'])) {
        // Legacy/alternate storage fallback
        $superuserFlags = (int) $_SESSION['user']['superuser'];
    }

    return \defined('SU_MEGAUSER') && ($superuserFlags & SU_MEGAUSER) === SU_MEGAUSER;
}


/**
 * Normalize async identifier tokens (class/method) to avoid log forging and
 * confusing diagnostics when these values are logged or echoed.
 *
 * Strips ASCII control characters (including newlines) and trims whitespace,
 * but otherwise leaves printable characters untouched.
 */
function lotgd_async_sanitize_token(string $value): string
{
    // Remove ASCII control characters: 0x00-0x1F and 0x7F.
    $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    if ($sanitized === null) {
        $sanitized = '';
    }

    return trim($sanitized);
}

/**
 * Build best-effort async callable context from incoming request payload.
 *
 * Different Jaxon versions can use different keys for class/method metadata. We capture
 * whichever keys are present so diagnostic IDs can be correlated with handler targets.
 *
 * @return array{class:string,method:string}
 */
function lotgd_async_request_context(): array
{
    $class = '';
    $method = '';

    foreach (['jxncls', 'jxnpkg', 'class', 'callable'] as $classKey) {
        $value = $_POST[$classKey] ?? $_GET[$classKey] ?? null;
        if (is_string($value)) {
            $sanitized = lotgd_async_sanitize_token($value);
            if ($sanitized !== '') {
                $class = $sanitized;
                break;
            }
        }
    }

    foreach (['jxnmthd', 'method', 'func', 'function'] as $methodKey) {
        $value = $_POST[$methodKey] ?? $_GET[$methodKey] ?? null;
        if (is_string($value)) {
            $sanitized = lotgd_async_sanitize_token($value);
            if ($sanitized !== '') {
                $method = $sanitized;
                break;
            }
        }
    }

    return [
        'class' => $class,
        'method' => $method,
    ];
}

// Simple rate limiting for Jaxon requests.  If an Ajax request arrives less
// than the configured threshold after the previous one, we respond with HTTP 429 and skip
// executing the handler.  The timestamp is only updated when the request is
// accepted to avoid locking out legitimate retries.
if ($jaxon->canProcessRequest()) {
    $now       = microtime(true);
    $threshold = $ajax_rate_limit_seconds ?? 1.0; // from async settings with fallback

    if (isset($_SESSION['lastrequest']) && ($now - $_SESSION['lastrequest']) < $threshold) {
        $requestContext = lotgd_async_request_context();
        $diagnosticId = lotgd_async_correlation_id();
        error_log(sprintf(
            'Jaxon rate limit hit [diag=%s handler=%s::%s]: threshold=%s now=%s last=%s',
            $diagnosticId,
            $requestContext['class'] !== '' ? $requestContext['class'] : 'unknown',
            $requestContext['method'] !== '' ? $requestContext['method'] : 'unknown',
            (string) $threshold,
            (string) $now,
            isset($_SESSION['lastrequest']) ? (string) $_SESSION['lastrequest'] : 'unset'
        ));

        $payload = [
            'status' => 'error',
            'error' => 'rate_limited',
            'message' => 'Too Many Requests',
        ];

        if (lotgd_async_is_megauser()) {
            $payload['diagnostic_id'] = $diagnosticId;
            $payload['diagnostic'] = [
                'handler_class' => $requestContext['class'],
                'handler_method' => $requestContext['method'],
            ];
        }

        lotgd_async_emit_error_payload(429, $payload);
        exit;
    }

    $_SESSION['lastrequest'] = $now;

    try {
        $jaxon->processRequest();
    } catch (\Throwable $e) {
        $diagnosticId = lotgd_async_correlation_id();

        $requestContext = lotgd_async_request_context();
        error_log(sprintf(
            'Jaxon processing exception [diag=%s handler=%s::%s]: class=%s message=%s file=%s line=%d trace=%s',
            $diagnosticId,
            $requestContext['class'] !== '' ? $requestContext['class'] : 'unknown',
            $requestContext['method'] !== '' ? $requestContext['method'] : 'unknown',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        $payload = [
            'status' => 'error',
            'error' => 'server_error',
            'message' => 'Server Error',
        ];

        if (lotgd_async_is_megauser()) {
            $payload['diagnostic_id'] = $diagnosticId;
            $payload['diagnostic'] = [
                'handler_class' => $requestContext['class'],
                'handler_method' => $requestContext['method'],
                'type' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        lotgd_async_emit_error_payload(500, $payload);
    }
} else {
    $requestContext = lotgd_async_request_context();
    $diagnosticId = lotgd_async_correlation_id();
    error_log(sprintf(
        'Jaxon bad request [diag=%s handler=%s::%s]: canProcessRequest returned false',
        $diagnosticId,
        $requestContext['class'] !== '' ? $requestContext['class'] : 'unknown',
        $requestContext['method'] !== '' ? $requestContext['method'] : 'unknown'
    ));

    $payload = [
        'status' => 'error',
        'error' => 'bad_request',
        'message' => 'Bad Request',
    ];

    if (lotgd_async_is_megauser()) {
        $payload['diagnostic_id'] = $diagnosticId;
        $payload['diagnostic'] = [
            'handler_class' => $requestContext['class'],
            'handler_method' => $requestContext['method'],
        ];
    }

    lotgd_async_emit_error_payload(400, $payload);
}
