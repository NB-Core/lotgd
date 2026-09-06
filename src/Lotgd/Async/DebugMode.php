<?php

declare(strict_types=1);

namespace Lotgd\Async;

/**
 * Runtime switch for verbose async diagnostics in the browser console.
 *
 * Enabled through `debug_console` in `config/async.settings.php` and consumed by
 * `async/setup.php`, which gates the informational `console.log` output of the
 * polling client on it. Error output is never gated.
 *
 * This is deliberately a static holder rather than a plain variable:
 * `async/common/settings.php` is pulled in with `require_once` from several entry
 * points, so whichever one loads it first decides the variable scope. Anything that
 * has to survive that is kept in a singleton or static, the same way the polling
 * intervals live on {@see \Lotgd\Async\Handler\Timeout}.
 */
final class DebugMode
{
    private static bool $enabled = false;

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
}
