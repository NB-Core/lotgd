<?php

namespace Lotgd;

/**
 * Simple bootstrap error handler that logs to logs/bootstrap.log.
 */
class BootstrapErrorHandler
{
    private const LOG_FILE = __DIR__ . '/../../logs/bootstrap.log';

    /**
     * Append one entry to logs/bootstrap.log.
     *
     * Three places used to write this file, in three formats, and this one wrote
     * every entry twice -- once through error_log() and once through
     * file_put_contents(). They all come through here now, so an operator reading
     * the file sees one shape of line and each event once.
     *
     * Failures are swallowed on purpose: in the Docker image the directory is
     * root-owned and not writable by the web user, where PHP's own error log is
     * the container log and takes the entry instead.
     */
    public static function log(string $message): void
    {
        $entry = sprintf('[%s] %s', date('c'), $message);

        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (@file_put_contents(self::LOG_FILE, $entry . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($entry);
        }
    }

    /**
     * Register temporary error and exception handlers.
     */
    public static function register(): void
    {
        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            self::log(sprintf('%s in %s on line %d', $message, $file, $line));

            return false;
        });

        set_exception_handler(static function (\Throwable $throwable): void {
            self::log(sprintf(
                'Uncaught %s: %s in %s on line %d%s%s',
                get_class($throwable),
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine(),
                PHP_EOL,
                $throwable->getTraceAsString()
            ));
        });
    }
}
