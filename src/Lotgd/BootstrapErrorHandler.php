<?php

namespace Lotgd;

/**
 * Simple bootstrap error handler that logs to logs/bootstrap.log.
 */
class BootstrapErrorHandler
{
    private const LOG_FILE = __DIR__ . '/../../logs/bootstrap.log';

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
            $entry = sprintf('[%s] %s in %s on line %d', date('c'), $message, $file, $line);
            error_log($entry);
            file_put_contents(self::LOG_FILE, $entry . PHP_EOL, FILE_APPEND);

            return false;
        });

        set_exception_handler(static function (\Throwable $throwable): void {
            $entry = sprintf(
                '[%s] Uncaught %s: %s in %s on line %d%s%s',
                date('c'),
                get_class($throwable),
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine(),
                PHP_EOL,
                $throwable->getTraceAsString()
            );
            error_log($entry);
            file_put_contents(self::LOG_FILE, $entry . PHP_EOL, FILE_APPEND);
        });
    }
}
