<?php

declare(strict_types=1);

/**
 * The realtime outbox as server-sent events (docs/ReactiveCore.md, Phase 7):
 * an EventSource on the page reads the same announcements the poll answers
 * (Lotgd\Realtime\Stream), within a second of the relay.
 *
 * Only with the setting realtime_transport = sse. An EventSource sends no
 * custom header, so the async CSRF token travels in the query and is always
 * checked, and a browser that says the request is cross-site is turned away.
 * The session is released before the stream starts: a stream that holds it
 * would block every other page of the player. Any answer but the stream
 * itself makes the page fall back to polling.
 */

define('OVERRIDE_FORCED_NAV', true);

if (!defined('LOTGD_STREAM_TEST_MODE')) {
    require_once __DIR__ . '/common/bootstrap.php';
    require_once __DIR__ . '/common/settings.php';
}

/**
 * Why the stream may not start, as [status, error]; null when it may.
 *
 * @return array{0:int,1:string}|null
 */
function lotgd_stream_refusal(): ?array
{
    global $session, $ajax_rate_limit_seconds;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return [405, 'method_not_allowed'];
    }
    // Sent by every current browser; an old one sends nothing, and the
    // token below still has to match.
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && $site !== 'same-origin') {
        return [403, 'cross_site'];
    }

    $user = $session['user'] ?? [];
    if ((int) ($user['acctid'] ?? 0) <= 0 || empty($user['loggedin'])) {
        return [401, 'unauthenticated'];
    }
    if (!\Lotgd\Realtime::enabledFor($user) || !\Lotgd\Realtime\Stream::enabled()) {
        return [404, 'disabled'];
    }
    if (!\Lotgd\Security\Csrf::matches(\Lotgd\Security\Csrf::SCOPE_ASYNC, $_GET['csrf'] ?? null)) {
        return [403, 'csrf_invalid'];
    }

    $now = microtime(true);
    $last = $_SESSION['lastrequest']['stream'] ?? null;
    if (is_numeric($last) && $now - (float) $last < (float) ($ajax_rate_limit_seconds ?? 1.0)) {
        return [429, 'rate_limited'];
    }
    $_SESSION['lastrequest']['stream'] = $now;

    return null;
}

/**
 * The cursor the stream starts after: the browser's Last-Event-ID when it
 * reconnects, else the page's.
 */
function lotgd_stream_cursor(): int
{
    $value = $_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['cursor'] ?? '0');

    return is_string($value) && ctype_digit($value) ? (int) $value : 0;
}

/**
 * @param int $keepBuffers Output buffers to leave open (tests read the stream from one).
 */
function lotgd_stream_entrypoint(int $keepBuffers = 0): void
{
    global $session;

    $refusal = lotgd_stream_refusal();
    if ($refusal !== null && $refusal[0] === 403) {
        // A cross-site request or a token that does not match: what the
        // async endpoint records for the same refusal. Error log only -- a
        // browser can repeat it at will (the page retries a refused stream).
        \Lotgd\SecurityLog::event(
            'Async stream refused',
            ['reason' => $refusal[1]],
            (int) ($session['user']['acctid'] ?? 0) ?: null,
            \Lotgd\GameLog::SEVERITY_WARNING,
            false
        );
    }
    if ($refusal !== null) {
        if (!headers_sent()) {
            http_response_code($refusal[0]);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(['status' => 'error', 'error' => $refusal[1]]);

        return;
    }

    $acctid = (int) $session['user']['acctid'];
    $section = is_string($_GET['section'] ?? null) ? substr((string) $_GET['section'], 0, 64) : '';
    $token = \Lotgd\Realtime\Stream::claim($acctid);

    // Read-only from here: the session stays readable, writes would be lost.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Every chunk must reach the browser as it is written.
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > $keepBuffers) {
        ob_end_clean();
    }
    $lifetime = \Lotgd\Realtime\Stream::lifetime();
    if (!headers_sent()) {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-store, no-transform');
        header('X-Accel-Buffering: no');
    }
    ignore_user_abort(false);
    @set_time_limit($lifetime + 15);

    $stream = new \Lotgd\Realtime\Stream(
        $acctid,
        $section,
        lotgd_stream_cursor(),
        $token,
        (float) $lifetime,
        static function (string $chunk): void {
            echo $chunk;
            flush();
        },
        static function (float $seconds): void {
            usleep((int) ($seconds * 1000000));
        },
        static fn (): float => microtime(true),
        static fn (): bool => connection_aborted() === 1
    );
    $stream->run();
}

if (!defined('LOTGD_STREAM_TEST_MODE')) {
    lotgd_stream_entrypoint();
}
