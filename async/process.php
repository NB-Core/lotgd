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
 * Kept as a thin wrapper so existing callers and tests keep working; the
 * implementation now lives with the rest of the security logging so an async
 * line and a game log row carry ids from the same source.
 *
 * @return string Correlation identifier for request/diagnostic stitching.
 */
function lotgd_async_correlation_id(): string
{
    return \Lotgd\SecurityLog::correlationId();
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
 * Decode the canonical Jaxon dispatch target from the request payload.
 *
 * Jaxon 5 encodes the target as a single JSON object in the `jxncall` field
 * ({@see \Jaxon\Request\Handler\ParameterReader::setRequestParameter()}) and
 * dispatches on its `name`/`method` keys
 * ({@see \Jaxon\Plugin\Request\CallableComponent\ComponentPlugin::makeCallableAction()}).
 * It does not understand any of the separate legacy fields, so this is the only
 * source that is guaranteed to describe the callable Jaxon will actually run.
 *
 * @return array{class:string,method:string}|null Null when no usable descriptor is present.
 */
function lotgd_async_jxncall_context(): ?array
{
    $raw = $_POST['jxncall'] ?? $_GET['jxncall'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    // Mirror ParameterReader::decodeStr(): the client only url-encodes parameters
    // when the request carries file uploads.
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $multipart = 'multipart/form-data';
    if (strncmp($contentType, $multipart, strlen($multipart)) === 0) {
        $raw = urldecode($raw);
    }

    $call = json_decode($raw, true);
    if (!is_array($call) || ($call['type'] ?? '') !== 'class') {
        return null;
    }

    $className = $call['name'] ?? null;
    $methodName = $call['method'] ?? null;
    if (!is_string($className) || !is_string($methodName)) {
        return null;
    }

    // Jaxon applies trim() to both values. lotgd_async_sanitize_token() additionally
    // strips control characters so they cannot reach error_log(); a name that differs
    // between the two is rejected by Jaxon's own validator and never dispatched.
    return [
        'class' => lotgd_async_sanitize_token($className),
        'method' => lotgd_async_sanitize_token($methodName),
    ];
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
    // The `jxncall` descriptor is authoritative whenever the field is present at all.
    // Honouring both sources would let a crafted payload describe one callable to this
    // policy layer and a different one to Jaxon, so authorization and the session-lock
    // decision could be taken for a handler that never runs.
    $jxncall = lotgd_async_jxncall_context();
    if ($jxncall !== null) {
        return $jxncall;
    }

    // A `jxncall` field that is present but unusable (malformed JSON, a non-class
    // descriptor, missing name/method) yields an unknown callable rather than falling
    // through to the legacy fields, which the same request could have set to anything.
    // An unknown callable is denied a lock release and logged as such.
    if (isset($_POST['jxncall']) || isset($_GET['jxncall'])) {
        return ['class' => '', 'method' => ''];
    }

    // Only reached when the payload carries no descriptor at all. Jaxon does not
    // dispatch such a request (both ComponentPlugin::canProcessRequest() and
    // FunctionPlugin::canProcessRequest() require the attribute), so these fields
    // never decide anything and only feed diagnostics.
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

    // Debug seams that are excluded from the Jaxon export in
    // async/common/jaxon.php. Repeated here so the refusal does not depend on
    // the registration options staying correct.
    static $deniedMethods = [
        'Lotgd.Async.Handler.Commentary' => ['test'],
    ];
    if (in_array($methodName, $deniedMethods[$className] ?? [], true)) {
        return false;
    }

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
 * Superuser bits a callable requires beyond a valid login.
 *
 * Authentication is not authorization. The pages that trigger these calls gate
 * themselves — bans.php:24 runs SuAccess::check(SU_EDIT_BANS) — but the async
 * entry point reaches the handler directly, so the page-level gate never runs.
 * Anything listed here is refused unless the account carries the bits.
 *
 * Constant names rather than values: constants.php is loaded by common.php, and
 * this file is also required standalone (LOTGD_ASYNC_PROCESS_TEST_MODE), so the
 * value is resolved when the request is evaluated rather than when this file is
 * parsed. An unresolvable name denies, see lotgd_async_has_required_privileges().
 *
 * @return array<string, array<string, string>>
 */
function lotgd_async_required_superuser_bits(): array
{
    return [
        'Lotgd.Async.Handler.Bans' => [
            'affectedUsers' => 'SU_EDIT_BANS',
        ],
    ];
}

/**
 * Check the account's superuser bits against the requirement for this callable.
 *
 * Deliberately a plain bitmask test rather than SuAccess::check(): that helper
 * renders a page, calls pageHeader()/pageFooter() and, on failure, zeroes the
 * character's gold and hitpoints and posts to the news. Reaching it from a JSON
 * endpoint would turn an unauthorized async call into character destruction.
 *
 * @param array{class:string,method:string} $requestContext
 */
function lotgd_async_has_required_privileges(array $requestContext): bool
{
    global $session;

    $required = lotgd_async_required_superuser_bits();
    $className = $requestContext['class'] ?? '';
    $methodName = $requestContext['method'] ?? '';

    $constantName = $required[$className][$methodName] ?? null;
    if ($constantName === null) {
        return true;
    }

    if (!defined($constantName)) {
        error_log("Jaxon authorization: $constantName is not defined; denying $className.$methodName");

        return false;
    }

    $bits = (int) constant($constantName);

    return ($bits & (int) ($session['user']['superuser'] ?? 0)) !== 0;
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
 * Determine whether an async callable can run without persisting session state.
 *
 * The PHP session file is locked for the whole lifetime of a request. Polling
 * callables run every few seconds for every open tab, so holding that lock until
 * script shutdown serialises all concurrent page loads of the same player behind
 * the poll. Normal page loads already release the lock in
 * {@see \Lotgd\Page\Footer::pageFooter()}; the async entry point never reaches
 * that code path.
 *
 * The callables listed here only read session data. The single write they perform
 * (the cached `laston` value in the timeout handler) is paired with an
 * authoritative database UPDATE and is re-read from the accounts row by
 * {@see \Lotgd\ForcedNavigation::doForcedNav()} on the next request, so dropping
 * the in-session copy is behaviour neutral.
 *
 * Default-deny: anything not listed keeps the session open and behaves exactly as
 * before. This deliberately covers module-supplied handlers registered through the
 * Jaxon callable directory as well as the passkey ceremonies, which must persist
 * their challenge state across requests.
 *
 * @param array{class:string,method:string} $requestContext
 */
function lotgd_async_is_session_readonly_callable(array $requestContext): bool
{
    static $readOnlyCallables = [
        'Lotgd.Async.Handler.Bans' => ['affectedUsers'],
        // commentaryText is deliberately absent: it runs Commentary::viewCommentary(),
        // which persists last_comment_section/last_comment_scriptname/lastcommentid
        // for the next request.
        'Lotgd.Async.Handler.Commentary' => ['commentaryRefresh', 'pollUpdates', 'test'],
        'Lotgd.Async.Handler.Mail' => ['mailStatus'],
        'Lotgd.Async.Handler.Timeout' => ['timeoutStatus'],
    ];

    $className = $requestContext['class'] ?? '';
    $methodName = $requestContext['method'] ?? '';
    if ($className === '' || $methodName === '') {
        return false;
    }

    return isset($readOnlyCallables[$className])
        && in_array($methodName, $readOnlyCallables[$className], true);
}

/**
 * Release the PHP session lock before dispatching a read-only async callable.
 *
 * $_SESSION stays readable afterwards; only further writes stop being persisted.
 *
 * Note on the return value: session_write_close() reports whether there was a
 * session to close, not whether the data reached the save handler. A failing
 * handler still returns true (it only raises a warning), while a call without an
 * active session returns false. The guard above already covers that case, so in
 * practice this returns false only when no release was attempted. Do not read a
 * false result as "the session data was lost".
 *
 * @param array{class:string,method:string} $requestContext
 *
 * @return bool True when the session was closed, false when no release was attempted
 *              or PHP reported that there was no session to close.
 */
function lotgd_async_release_session_lock(array $requestContext): bool
{
    if (!lotgd_async_is_session_readonly_callable($requestContext)) {
        return false;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    return session_write_close();
}

/**
 * Evaluate the CSRF token the request carries, without deciding what to do.
 *
 * Never generates one: async/process.php may already have released the session
 * lock, so a token minted here would be handed out once and lost, which shows
 * up as an intermittent mismatch rather than a clear failure. See
 * {@see \Lotgd\Security\Csrf} on that invariant.
 *
 * @param array{class:string,method:string} $requestContext
 *
 * `valid` false means refuse where the mode enforces; `observed` true means a
 * failure worth logging that must not refuse regardless of the mode.
 *
 * @return array{required:bool,valid:bool,reason:string,observed:bool}
 */
function lotgd_async_csrf_state(array $requestContext): array
{
    if (!\Lotgd\Async\CsrfMode::isChecked()) {
        return ['required' => false, 'valid' => true, 'reason' => 'disabled', 'observed' => false];
    }

    $token = \Lotgd\Security\Csrf::requestToken();
    $reason = '';
    if ($token === '') {
        $reason = 'missing';
    } elseif (!\Lotgd\Security\Csrf::matches(\Lotgd\Security\Csrf::SCOPE_ASYNC, $token)) {
        $reason = 'mismatch';
    }

    // The pre-login passkey pair is checked but never refused, whatever the
    // configured mode says.
    //
    // It used to be skipped entirely, on the assumption that no session-scoped
    // token exists before a login. That is not so: every page that can reach
    // these two calls twofactorauth_force_async_bootstrap(), which loads
    // async/setup.php, which issues the token. So the check runs and its
    // result is recorded.
    //
    // Refusing on it is a different question, and the asymmetry decides it.
    // The gain is defence in depth on a path that already validates a token of
    // its own inside the handler; the loss, if any entry point turns out not
    // to bootstrap, is that nobody completes two-factor login. Evidence first:
    // a release without "Jaxon csrf" lines naming these two is what should
    // promote them, the same way the no-cors problem was found rather than
    // guessed.
    if (lotgd_async_is_unauth_allowlisted($requestContext)) {
        return ['required' => false, 'valid' => true, 'reason' => $reason, 'observed' => $reason !== ''];
    }

    return ['required' => true, 'valid' => $reason === '', 'reason' => $reason, 'observed' => false];
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

    // Evaluated once, before the branches, so the pre-login passkey pair is
    // observed too. It never reaches the authenticated branch below, so a check
    // that lived only in there would have collected no evidence at all about
    // the callables it was added for.
    $csrf = lotgd_async_csrf_state($requestContext);
    $authenticated = lotgd_async_is_authenticated();

    // Two cases are worth a line, and only those two: a logged-in caller whose
    // token did not check out -- a real player's poll, which is what the
    // promotion signal in UPGRADING.md is about -- and the observe-only passkey
    // pair, which is recorded precisely because it is never refused.
    //
    // An unauthenticated non-passkey request is deliberately not logged. Its
    // token is irrelevant: it is refused on authentication a few lines below
    // whatever the token says. Logging it would mean an idle tab whose session
    // timed out writes a "mismatch" line on every poll from then on, and a
    // bare POST to the endpoint writes a "missing" line for anyone who cares
    // to send one -- into the very log an operator is told must fall quiet
    // before setting `enforce`. The signal has to be about players, or it
    // never goes quiet and says nothing when it does.
    if (($authenticated && !$csrf['valid']) || $csrf['observed']) {
        $handler = ($requestContext['class'] ?? '') . '::' . ($requestContext['method'] ?? '');
        error_log(sprintf('Jaxon csrf %s [handler=%s mode=%s]', $csrf['reason'], $handler, \Lotgd\Async\CsrfMode::mode()));
    }

    if ($authenticated) {
        if (!lotgd_async_has_required_privileges($requestContext)) {
            return [
                'allowed' => false,
                'status' => 403,
                'error' => 'insufficient_privileges',
                'message' => 'Forbidden',
            ];
        }

        if (\Lotgd\Async\CsrfMode::isEnforced() && !$csrf['valid']) {
            return [
                'allowed' => false,
                'status' => 403,
                'error' => 'csrf_invalid',
                'message' => 'Forbidden',
            ];
        }

        return ['allowed' => true, 'status' => 200];
    }

    // The privilege check runs here too: a callable that is both unauth
    // allowlisted and privilege-gated would be a contradiction, and this makes
    // that contradiction deny rather than grant.
    if (lotgd_async_is_unauth_allowlisted($requestContext) && lotgd_async_has_required_privileges($requestContext)) {
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
 * We intentionally favor stable network identity (IP + User-Agent) and only
 * append the session cookie when it is explicitly supplied by the client. This
 * avoids a bypass where cookie-less unauthenticated traffic receives a new
 * PHP session id each request and evades throttling.
 */
function lotgd_async_abuse_key(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown_ip';
    $ip = lotgd_async_sanitize_token($ip);

    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
        ? lotgd_async_sanitize_token($_SERVER['HTTP_USER_AGENT'])
        : 'unknown_ua';

    $sessionCookieName = session_name();
    if ($sessionCookieName !== '' && isset($_COOKIE[$sessionCookieName]) && is_string($_COOKIE[$sessionCookieName])) {
        return hash('sha256', $ip . '|' . $userAgent . '|cookie:' . $_COOKIE[$sessionCookieName]);
    }

    return hash('sha256', $ip . '|' . $userAgent . '|no_cookie');
}

/**
 * Return the shared file path used for denied-request throttle state.
 */
function lotgd_async_denied_throttle_store_path(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lotgd_async_denied_throttle.json';
}

/**
 * Prune throttle bookkeeping to keep state bounded.
 *
 * @param array<string, float|int|string> $store
 */
function lotgd_async_prune_denied_throttle_store(array &$store, float $now, float $threshold): void
{
    $ttl = max($threshold * 10.0, 5.0);
    foreach ($store as $key => $value) {
        if (!is_numeric($value) || ($now - (float) $value) > $ttl) {
            unset($store[$key]);
        }
    }

    $maxEntries = 256;
    if (count($store) > $maxEntries) {
        asort($store);
        $store = array_slice($store, -$maxEntries, null, true);
    }
}

/**
 * Check and update deny-throttle state for denied/unauthenticated requests.
 *
 * State is stored in a process-shared cache (APCu when available, otherwise a
 * small temp-file map) so throttling remains effective even when requests do
 * not carry a stable PHP session cookie.
 */
function lotgd_async_denied_request_is_throttled(float $now, float $threshold): bool
{
    $key = lotgd_async_abuse_key();
    $ttlSeconds = max((int) ceil($threshold * 10.0), 5);

    if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled') ?: '0', FILTER_VALIDATE_BOOLEAN)) {
        $apcuKey = 'lotgd:async:deny:' . $key;
        $last = apcu_fetch($apcuKey, $success);
        if ($success && is_numeric($last) && ($now - (float) $last) < $threshold) {
            return true;
        }

        // APCu can appear available while writes fail in some CLI/CI environments.
        // Only short-circuit when the write succeeds; otherwise continue to fallback storage.
        if (apcu_store($apcuKey, $now, $ttlSeconds)) {
            return false;
        }
    }

    $storePath = lotgd_async_denied_throttle_store_path();
    $directory = dirname($storePath);
    if (!is_dir($directory) || !is_writable($directory)) {
        if (!isset($_SESSION['async_authz_denied_last']) || !is_array($_SESSION['async_authz_denied_last'])) {
            $_SESSION['async_authz_denied_last'] = [];
        }

        lotgd_async_prune_denied_throttle_store($_SESSION['async_authz_denied_last'], $now, $threshold);
        $last = $_SESSION['async_authz_denied_last'][$key] ?? null;
        if (is_numeric($last) && ($now - (float) $last) < $threshold) {
            return true;
        }

        $_SESSION['async_authz_denied_last'][$key] = $now;

        return false;
    }

    $handle = fopen($storePath, 'c+');
    if ($handle === false) {
        return false;
    }

    $isThrottled = false;
    if (flock($handle, LOCK_EX)) {
        $contents = stream_get_contents($handle);
        $store = [];
        if (is_string($contents) && $contents !== '') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $store = $decoded;
            }
        }

        lotgd_async_prune_denied_throttle_store($store, $now, $threshold);
        $last = $store[$key] ?? null;
        if (is_numeric($last) && ($now - (float) $last) < $threshold) {
            $isThrottled = true;
        } else {
            $store[$key] = $now;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($store) ?: '{}');
        fflush($handle);
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    return $isThrottled;
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
            $now = microtime(true);
            $threshold = $ajax_rate_limit_seconds ?? 1.0;
            $throttled = lotgd_async_denied_request_is_throttled($now, (float) $threshold);
            // An unauthenticated caller can repeat this request at will, so the
            // database only takes the denials the throttle lets through. The error
            // log, a cheap append, still takes every one of them.
            $diagnosticId = \Lotgd\SecurityLog::event(
                'Async request denied by the authorization policy',
                [
                    'handler' => ($requestContext['class'] !== '' ? $requestContext['class'] : 'unknown')
                        . '::' . ($requestContext['method'] !== '' ? $requestContext['method'] : 'unknown'),
                    'status' => (int) ($authorization['status'] ?? 403),
                    'reason' => (string) ($authorization['error'] ?? 'forbidden'),
                ],
                null,
                \Lotgd\GameLog::SEVERITY_WARNING,
                ! $throttled
            );

            if ($throttled) {
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
            $diagnosticId = \Lotgd\SecurityLog::correlationId();
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

        // Release the session lock before dispatch so read-only polling callables
        // stop serialising concurrent page loads of the same player.
        lotgd_async_release_session_lock($requestContext);

        try {
            $jaxon->processRequest();
        } catch (\Throwable $e) {
            $diagnosticId = \Lotgd\SecurityLog::event(
                'Async handler raised an exception',
                [
                    'handler' => ($requestContext['class'] !== '' ? $requestContext['class'] : 'unknown')
                        . '::' . ($requestContext['method'] !== '' ? $requestContext['method'] : 'unknown'),
                    'type' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                null,
                \Lotgd\GameLog::SEVERITY_ERROR
            );
            // The trace stays out of the game log and goes to the error log only.
            error_log(sprintf('[security] async handler trace [diag=%s] %s', $diagnosticId, $e->getTraceAsString()));

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
        $diagnosticId = \Lotgd\SecurityLog::correlationId();
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
