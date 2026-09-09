<?php

declare(strict_types=1);

namespace Lotgd\Async;

/**
 * How strictly async/process.php treats a missing or wrong CSRF token.
 *
 * Configured through `csrf_mode` in `config/async.settings.php`, consumed by
 * `async/process.php`.
 *
 * The endpoint needs its own check because the navigation allowlist that
 * protects ordinary pages does not apply here: `async/process.php` defines
 * `OVERRIDE_FORCED_NAV`, which makes both branches of
 * `ForcedNavigation::doForcedNav()` no-ops, so a request is authenticated by
 * the session cookie alone. The session cookie's `SameSite=Lax` default, set
 * in `common.php` before the AJAX branch, keeps a cross-site POST from
 * carrying it at all — unless an operator sets `SESSION_COOKIE_SAMESITE` to
 * `None`, which is supported.
 *
 * Hence three modes rather than a boolean. The token travels in a custom
 * request header added by `async/js/lotgd.jaxon.js`; {@see self::LOG} exists
 * because a broken transport there is invisible from the server side, and
 * polling runs every few seconds for every player with the AJAX preference on,
 * so enforcing a check that silently never receives its token would break all
 * of them at once, with a symptom (polling stops) that looks nothing like the
 * cause.
 *
 * That was not hypothetical. Jaxon defaults `httpRequestOptions.mode` to
 * `no-cors`, under which the browser drops every non-safelisted request header
 * — the async token included, and on same-origin requests too. The first
 * release shipped on LOG for exactly that reason and would have logged a
 * failure on every poll. The client now sets `same-origin`, the Jaxon runtime
 * is served from the tree rather than a CDN, and the header was confirmed to
 * arrive in a real browser against those files.
 *
 * The shipped default is still LOG, for a second reason that the transport
 * check does not address: the client is inlined into each page, so a page
 * rendered before an upgrade keeps the old one — no `same-origin`, and no
 * recovery handler. Enforcing at upgrade time would strand those tabs. The
 * promotion to ENFORCE belongs to the operator, once the tabs have aged out
 * and `error_log` is quiet.
 *
 * Static holder rather than a variable for the same reason as
 * {@see DebugMode}: `async/common/settings.php` is pulled in with
 * `require_once` from several entry points, so whichever loads it first would
 * decide the variable's scope.
 */
final class CsrfMode
{
    /** Do not look at the token at all. */
    public const OFF = 'off';

    /** Check it, record what fails, let the request through. */
    public const LOG = 'log';

    /** Check it and refuse the request when it does not match. */
    public const ENFORCE = 'enforce';

    private static string $mode = self::LOG;

    /**
     * Unknown values fall back to LOG rather than to OFF or ENFORCE: a typo in
     * the config should neither disable the check silently nor start rejecting
     * live traffic.
     */
    public static function setMode(string $mode): void
    {
        $mode = strtolower(trim($mode));
        self::$mode = in_array($mode, [self::OFF, self::LOG, self::ENFORCE], true)
            ? $mode
            : self::LOG;
    }

    public static function mode(): string
    {
        return self::$mode;
    }

    public static function isChecked(): bool
    {
        return self::$mode !== self::OFF;
    }

    public static function isEnforced(): bool
    {
        return self::$mode === self::ENFORCE;
    }
}
