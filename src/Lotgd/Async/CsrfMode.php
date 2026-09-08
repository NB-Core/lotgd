<?php

declare(strict_types=1);

namespace Lotgd\Async;

/**
 * How strictly async/process.php treats a missing or wrong CSRF token.
 *
 * Configured through `csrf_mode` in `config/async.settings.php`, consumed by
 * `async/process.php`.
 *
 * The endpoint has no token check at all today. What keeps a cross-site call
 * out is the session cookie's `SameSite=Lax` default, set in `common.php`
 * before the AJAX branch — so the gap only becomes live if an operator sets
 * `SESSION_COOKIE_SAMESITE` to `None`, which is supported. The navigation
 * allowlist that protects ordinary pages does not apply here:
 * `async/process.php` defines `OVERRIDE_FORCED_NAV`, which makes both branches
 * of `ForcedNavigation::doForcedNav()` no-ops.
 *
 * Hence three modes rather than a boolean. The token has to travel in a custom
 * request header added by `async/js/lotgd.jaxon.js`, and the Jaxon runtime that
 * carries it is fetched from a CDN rather than vendored, so it cannot be
 * verified in CI. {@see self::LOG} makes a broken transport a log line instead
 * of an outage: polling runs every few seconds for every player with the AJAX
 * preference on, so enforcing a check that silently never receives its token
 * would break all of them at once, with a symptom (polling stops) that looks
 * nothing like the cause.
 *
 * The intended path is to ship on LOG, read `error_log` over a release, and
 * switch the shipped default to ENFORCE once it stays quiet.
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
