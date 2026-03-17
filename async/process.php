<?php

declare(strict_types=1);

/**
 * Entry point for processing Jaxon AJAX requests. Loads the
 * lightweight environment, common settings and server callbacks,
 * then passes control to the Jaxon engine.
 */

// File ajax_process.php
define("OVERRIDE_FORCED_NAV", true);

if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
    require_once __DIR__ . '/common/bootstrap.php';
    require_once __DIR__ . '/common/jaxon.php';
}

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
 * @param int                  $statusCode HTTP status code to send with the response.
 * @param array<string, mixed> $payload    Structured error payload to JSON-encode.
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

/**
 * Validate async callable routing against a minimal hardening allowlist.
 *
 * Compatibility note: this must not change client-side Jaxon exports. We enforce
 * sensitive passkey method restrictions on the server so the historical
 * Lotgd.Async.Handler namespace remains stable for browser bridge code.
 *
 * @param array{class:string,method:string} $requestContext
 */
function lotgd_async_is_allowed_callable(array $requestContext): bool
{
    $className = $requestContext['class'] ?? '';
    $methodName = $requestContext['method'] ?? '';

    if ($className !== 'Lotgd.Async.Handler.TwoFactorAuthPasskey') {
        return true;
    }

    return in_array($methodName, [
        'beginRegistration',
        'finishRegistration',
        'beginAuthentication',
        'verifyAuthentication',
    ], true);
}

/**
 * Determine whether the current async request has an authenticated game session.
 *
 * We intentionally check both legacy and nested session locations because 2.x
 * keeps compatibility paths for legacy modules and entry points.
 */
function lotgd_async_is_authenticated(): bool
{
    global $session;

    if (isset($session['user']['loggedin'])) {
        return (bool) $session['user']['loggedin'];
    }

    if (isset($session['loggedin'])) {
        return (bool) $session['loggedin'];
    }

    if (isset($_SESSION['session']['user']['loggedin'])) {
        return (bool) $_SESSION['session']['user']['loggedin'];
    }

    if (isset($_SESSION['user']['loggedin'])) {
        return (bool) $_SESSION['user']['loggedin'];
    }

    return false;
}

/**
 * Check if an unauthenticated request is explicitly allowed.
 *
 * Default-deny model: every unauthenticated async callable is denied unless
 * listed here. This central policy executes before Jaxon dispatch so handlers
 * are protected even when they also enforce auth/CSRF checks internally.
 *
 * @param array{class:string,method:string} $requestContext
 */
function lotgd_async_is_unauth_allowlisted(array $requestContext): bool
{
    static $allowlist = [
        'Lotgd.Async.Handler.TwoFactorAuthPasskey' => [
            'beginAuthentication',
            'verifyAuthentication',
        ],
    ];

    $className = $requestContext['class'] ?? '';
    $methodName = $requestContext['method'] ?? '';
    if ($className === '' || $methodName === '') {
        return false;
    }

    return isset($allowlist[$className]) && in_array($methodName, $allowlist[$className], true);
}

/**
 * Evaluate async authorization policy for the requested callable.
 *
 * @param array{class:string,method:string} $requestContext
 *
 * @return array{allowed:bool,status:int,error?:string,message?:string}
 */
function lotgd_async_authorization_policy(array $requestContext): array
{
    if (!lotgd_async_is_allowed_callable($requestContext)) {
        return [
            'allowed' => false,
            'status' => 403,
            'error' => 'callable_not_allowed',
            'message' => 'Forbidden',
        ];
    }

    if (lotgd_async_is_authenticated()) {
        return ['allowed' => true, 'status' => 200];
    }

    if (lotgd_async_is_unauth_allowlisted($requestContext)) {
        return ['allowed' => true, 'status' => 200];
    }

    return [
        'allowed' => false,
        'status' => 401,
        'error' => 'authentication_required',
        'message' => 'Unauthorized',
    ];
}

/**
 * Build an abuse throttling key for denied/unauth async attempts.
 *
 * This is intentionally independent from $_SESSION['lastrequest'] so authz
 * abuse controls cannot be bypassed or coupled to normal accepted request flow.
 */
function lotgd_async_abuse_key(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown_ip';
    $ip = lotgd_async_sanitize_token($ip);

    $sessionFragment = '';
    $sessionCookieName = session_name();
    if ($sessionCookieName !== '' && isset($_COOKIE[$sessionCookieName]) && is_string($_COOKIE[$sessionCookieName])) {
        $sessionFragment = $_COOKIE[$sessionCookieName];
    } elseif (session_id() !== '') {
        $sessionFragment = session_id();
    } else {
        $sessionFragment = 'no_session';
    }

    return hash('sha256', $ip . '|' . $sessionFragment);
}

/**
 * Check and update deny-throttle state for denied/unauthenticated requests.
 */
function lotgd_async_denied_request_is_throttled(float $now, float $threshold): bool
{
    if (!isset($_SESSION['async_authz_denied_last']) || !is_array($_SESSION['async_authz_denied_last'])) {
        $_SESSION['async_authz_denied_last'] = [];
    }

    $key = lotgd_async_abuse_key();
    $last = $_SESSION['async_authz_denied_last'][$key] ?? null;
    if (is_numeric($last) && ($now - (float) $last) < $threshold) {
        return true;
    }

    $_SESSION['async_authz_denied_last'][$key] = $now;

    return false;
}

/**
 * Run the async request processing pipeline.
 */
function lotgd_async_process_entrypoint(): void
{
    global $jaxon, $ajax_rate_limit_seconds;

    // Simple rate limiting for Jaxon requests. If an Ajax request arrives less
    // than the configured threshold after the previous one, we respond with HTTP 429 and skip
    // executing the handler. The timestamp is only updated when the request is
    // accepted to avoid locking out legitimate retries.
    if ($jaxon->canProcessRequest()) {
        $requestContext = lotgd_async_request_context();
        $authorization = lotgd_async_authorization_policy($requestContext);
        if (!($authorization['allowed'] ?? false)) {
            $diagnosticId = lotgd_async_correlation_id();
            error_log(sprintf(
                'Jaxon authz denied [diag=%s handler=%s::%s status=%d reason=%s]',
                $diagnosticId,
                $requestContext['class'] !== '' ? $requestContext['class'] : 'unknown',
                $requestContext['method'] !== '' ? $requestContext['method'] : 'unknown',
                (int) ($authorization['status'] ?? 403),
                (string) ($authorization['error'] ?? 'forbidden')
            ));

            $now = microtime(true);
            $threshold = $ajax_rate_limit_seconds ?? 1.0;
            if (lotgd_async_denied_request_is_throttled($now, (float) $threshold)) {
                lotgd_async_emit_error_payload(429, [
                    'status' => 'error',
                    'error' => 'rate_limited',
                    'message' => 'Too Many Requests',
                ]);

                return;
            }

            $payload = [
                'status' => 'error',
                'error' => (string) ($authorization['error'] ?? 'forbidden'),
                'message' => (string) ($authorization['message'] ?? 'Forbidden'),
            ];

            if (lotgd_async_is_megauser()) {
                $payload['diagnostic_id'] = $diagnosticId;
                $payload['diagnostic'] = [
                    'handler_class' => $requestContext['class'],
                    'handler_method' => $requestContext['method'],
                ];
            }

            lotgd_async_emit_error_payload((int) ($authorization['status'] ?? 403), $payload);

            return;
        }

        $now       = microtime(true);
        $threshold = $ajax_rate_limit_seconds ?? 1.0; // from async settings with fallback

        if (isset($_SESSION['lastrequest']) && ($now - $_SESSION['lastrequest']) < $threshold) {
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

            return;
        }

        $_SESSION['lastrequest'] = $now;

        try {
            $jaxon->processRequest();
        } catch (\Throwable $e) {
            $diagnosticId = lotgd_async_correlation_id();
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
}

if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
    lotgd_async_process_entrypoint();
}
