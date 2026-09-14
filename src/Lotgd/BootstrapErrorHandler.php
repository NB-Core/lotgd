<?php

namespace Lotgd;

/**
 * Simple bootstrap error handler that logs to logs/bootstrap.log, or to
 * wherever LOTGD_BOOTSTRAP_LOG points.
 */
class BootstrapErrorHandler
{
    private const DEFAULT_LOG_FILE = __DIR__ . '/../../logs/bootstrap.log';

    /**
     * The environment variable that moves the log somewhere else.
     */
    public const LOG_FILE_ENV = 'LOTGD_BOOTSTRAP_LOG';

    /**
     * Where entries go.
     *
     * The default is the same logs/bootstrap.log it has always been, so an
     * installation that sets nothing sees no change. The override exists
     * because the path is otherwise a process-global this class owns and
     * nobody else can move:
     *
     *   - In the Docker image the directory is root-owned and not writable by
     *     the web user, which is why log() swallows failures and falls back to
     *     PHP's own error log. An operator can now point this at a writable
     *     path and keep the entries instead of losing their shape.
     *   - A test that wants to read back what a subprocess logged had to use
     *     the one shared file, deleting it before and after -- so any other
     *     subprocess in the same suite writing a line could break it, and it
     *     could delete lines somebody else was appending. That is what this
     *     unblocks; see tests/CronCommonExceptionTest.
     */
    public static function logFile(): string
    {
        $override = getenv(self::LOG_FILE_ENV);
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return self::DEFAULT_LOG_FILE;
    }

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
        $logFile = self::logFile();

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (@file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($entry);
        }
    }

    /**
     * Register temporary error and exception handlers.
     */
    public static function register(): void
    {
        $dir = dirname(self::logFile());
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
